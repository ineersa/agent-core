<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Observer;

use Ineersa\Hatfield\ExtensionApi\Tool\ExtensionToolHandlerInterface;

/**
 * In-process tool that validates and collects Observer observations.
 *
 * Persistence happens after AgentRunner returns, not inside this tool.
 *
 * Model-correctable validation failures return a structured rejection so
 * Symfony AgentProcessor can feed the error back for another tool call.
 * Only the first valid invocation (including empty list) is accepted.
 */
final class RecordObservationsToolHandler implements ExtensionToolHandlerInterface
{
    /**
     * @var list<array{
     *   observation_id: string,
     *   content: string,
     *   content_hash: string,
     *   relevance: int,
     *   token_count: int,
     *   source_refs_json: string
     * }>
     */
    private array $collected = [];

    private bool $recorded = false;

    /**
     * @param list<array{run_id: string, seq: int}> $allowedSourceRefs
     */
    public function __construct(
        private readonly string $runId,
        private readonly string $boundaryKey,
        private readonly string $observerSchemaVersion,
        private readonly int $sourceStartSeq,
        private readonly int $sourceEndSeq,
        private readonly array $allowedSourceRefs,
        private readonly int $maxObservations,
        private readonly int $contentMaxChars,
    ) {
    }

    public function __invoke(array $arguments): mixed
    {
        if ($this->recorded) {
            return $this->reject(
                'already_recorded',
                'record_observations already accepted one invocation for this interaction; do not call it again.',
            );
        }

        $observations = $arguments['observations'] ?? null;
        if (!\is_array($observations)) {
            return $this->reject(
                'invalid_arguments',
                'record_observations requires observations as a list (array).',
            );
        }

        if (\count($observations) > $this->maxObservations) {
            return $this->reject(
                'too_many_observations',
                \sprintf('record_observations exceeded max observations (%d > %d).', \count($observations), $this->maxObservations),
            );
        }

        $seen = [];
        $validated = [];
        foreach ($observations as $index => $raw) {
            if (!\is_array($raw)) {
                return $this->reject(
                    'invalid_observation',
                    \sprintf('Observation at index %d must be an object.', $index),
                );
            }

            $content = $raw['content'] ?? null;
            if (!\is_string($content) || '' === trim($content)) {
                return $this->reject(
                    'invalid_content',
                    \sprintf('Observation at index %d must have non-empty content.', $index),
                );
            }
            $content = trim($content);
            if (mb_strlen($content, 'UTF-8') > $this->contentMaxChars) {
                return $this->reject(
                    'content_too_long',
                    \sprintf('Observation at index %d content exceeds %d characters.', $index, $this->contentMaxChars),
                );
            }

            $relevance = $raw['relevance'] ?? null;
            if (!is_numeric($relevance)) {
                return $this->reject(
                    'invalid_relevance',
                    \sprintf('Observation at index %d relevance must be numeric 0..100.', $index),
                );
            }
            $relevanceInt = (int) $relevance;
            if ($relevanceInt < 0 || $relevanceInt > 100) {
                return $this->reject(
                    'invalid_relevance',
                    \sprintf('Observation at index %d relevance must be 0..100.', $index),
                );
            }

            $sourceRefs = $raw['source_refs'] ?? $raw['sourceRefs'] ?? [];
            if (!\is_array($sourceRefs) || [] === $sourceRefs) {
                return $this->reject(
                    'missing_source_refs',
                    \sprintf('Observation at index %d must cite one or more source_refs.', $index),
                );
            }

            try {
                $normalizedRefs = $this->normalizeAndValidateRefs($sourceRefs, $index);
                $refsJson = json_encode($normalizedRefs, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            } catch (\InvalidArgumentException $e) {
                return $this->reject('invalid_source_refs', $e->getMessage());
            } catch (\JsonException $e) {
                // True internal failure: encoding should never fail for our normalized shape.
                throw new \RuntimeException('Failed to encode normalized source_refs.', previous: $e);
            }

            $contentHash = hash('sha256', $content);
            $dedupeKey = $contentHash.'|'.$refsJson;
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $observationId = hash('sha256', implode('|', [
                $this->runId,
                $this->boundaryKey,
                $this->observerSchemaVersion,
                $contentHash,
                $refsJson,
            ]));

            $validated[] = [
                'observation_id' => $observationId,
                'content' => $content,
                'content_hash' => $contentHash,
                'relevance' => $relevanceInt,
                'token_count' => OmTokenEstimator::estimate($content),
                'source_refs_json' => $refsJson,
            ];
        }

        // First valid invocation wins (including empty list). Later calls reject.
        $this->collected = $validated;
        $this->recorded = true;

        return [
            'status' => 'accepted',
            'observation_count' => \count($validated),
        ];
    }

    public function hasRecorded(): bool
    {
        return $this->recorded;
    }

    /**
     * @return list<array{
     *   observation_id: string,
     *   content: string,
     *   content_hash: string,
     *   relevance: int,
     *   token_count: int,
     *   source_refs_json: string
     * }>
     */
    public function collected(): array
    {
        return $this->collected;
    }

    /**
     * @return array{status: 'rejected', error: string, message: string}
     */
    private function reject(string $error, string $message): array
    {
        return [
            'status' => 'rejected',
            'error' => $error,
            'message' => $message,
        ];
    }

    /**
     * @param list<mixed> $sourceRefs
     *
     * @return list<array{run_id: string, seq: int}>
     */
    private function normalizeAndValidateRefs(array $sourceRefs, int $index): array
    {
        $allowed = [];
        foreach ($this->allowedSourceRefs as $ref) {
            $allowed[$ref['run_id'].'|'.$ref['seq']] = true;
        }

        $normalized = [];
        foreach ($sourceRefs as $ref) {
            if (!\is_array($ref)) {
                throw new \InvalidArgumentException(\sprintf('Observation %d source_refs entries must be objects.', $index));
            }
            $runId = (string) ($ref['run_id'] ?? $ref['runId'] ?? $this->runId);
            $seq = $ref['seq'] ?? null;
            if (!is_numeric($seq)) {
                throw new \InvalidArgumentException(\sprintf('Observation %d source_refs.seq must be numeric.', $index));
            }
            $seqInt = (int) $seq;
            if ($seqInt < $this->sourceStartSeq || $seqInt > $this->sourceEndSeq) {
                throw new \InvalidArgumentException(\sprintf('Observation %d source_refs.seq %d outside allowed range %d..%d.', $index, $seqInt, $this->sourceStartSeq, $this->sourceEndSeq));
            }
            $key = $runId.'|'.$seqInt;
            if (!isset($allowed[$key])) {
                throw new \InvalidArgumentException(\sprintf('Observation %d source_refs cite unknown (run_id, seq)=(%s, %d).', $index, $runId, $seqInt));
            }
            $normalized[] = ['run_id' => $runId, 'seq' => $seqInt];
        }

        usort($normalized, static function (array $a, array $b): int {
            $bySeq = $a['seq'] <=> $b['seq'];
            if (0 !== $bySeq) {
                return $bySeq;
            }

            return strcmp($a['run_id'], $b['run_id']);
        });

        // Dedupe identical refs after sort.
        $unique = [];
        $out = [];
        foreach ($normalized as $ref) {
            $k = $ref['run_id'].'|'.$ref['seq'];
            if (isset($unique[$k])) {
                continue;
            }
            $unique[$k] = true;
            $out[] = $ref;
        }

        return $out;
    }
}
