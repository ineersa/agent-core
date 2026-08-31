<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Observer;

use Ineersa\Hatfield\ExtensionApi\Tool\ExtensionToolHandlerInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Support\OmIdentity;

/**
 * Multi-call accumulate record_observations tool (task §F).
 *
 * Invalid calls return correction receipts without mutating accepted state.
 * Valid calls accumulate/dedupe until AgentRunner completes.
 */
final class RecordObservationsToolHandler implements ExtensionToolHandlerInterface
{
    /**
     * @var array<string, array{
     *   observation_id: string,
     *   content: string,
     *   content_hash: string,
     *   relevance: string,
     *   timestamp: string,
     *   token_count: int,
     *   source_refs_json: string
     * }>
     */
    private array $accepted = [];

    /**
     * @param list<array{run_id: string, seq: int}> $allowedSourceRefs
     */
    public function __construct(
        private readonly string $runId,
        private readonly string $observerSchemaVersion,
        private readonly array $allowedSourceRefs,
    ) {
    }

    public function __invoke(array $arguments): mixed
    {
        $observations = $arguments['observations'] ?? null;
        if (!\is_array($observations)) {
            return $this->receipt(
                added: 0,
                duplicates: 0,
                rejected: 1,
                status: 'rejected',
                message: 'record_observations requires observations as a list (array).',
                error: 'invalid_arguments',
            );
        }

        $added = 0;
        $duplicates = 0;
        $rejected = 0;

        foreach ($observations as $index => $raw) {
            if (!\is_array($raw)) {
                ++$rejected;
                continue;
            }

            $content = $raw['content'] ?? null;
            if (!\is_string($content)) {
                ++$rejected;
                continue;
            }
            $content = trim($content);
            if ('' === $content || str_contains($content, "\n")) {
                ++$rejected;
                continue;
            }

            $timestamp = $raw['timestamp'] ?? null;
            if (!\is_string($timestamp) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $timestamp)) {
                ++$rejected;
                continue;
            }

            $relevance = $raw['relevance'] ?? null;
            if (!\is_string($relevance) || !\in_array($relevance, OmIdentity::relevanceValues(), true)) {
                ++$rejected;
                continue;
            }

            $sourceRefs = $raw['source_refs'] ?? null;
            if (!\is_array($sourceRefs) || [] === $sourceRefs) {
                ++$rejected;
                continue;
            }

            try {
                $normalizedRefs = $this->normalizeAndValidateRefs($sourceRefs, $index);
                $refsJson = json_encode($normalizedRefs, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            } catch (\InvalidArgumentException) {
                ++$rejected;
                continue;
            } catch (\JsonException $e) {
                throw new \RuntimeException('Failed to encode normalized source_refs.', previous: $e);
            }

            $observationId = OmIdentity::observationId(
                $this->runId,
                $this->observerSchemaVersion,
                $timestamp,
                $content,
                $normalizedRefs,
            );
            $contentHash = hash('sha256', $content);

            if (isset($this->accepted[$observationId])) {
                ++$duplicates;
                continue;
            }

            $this->accepted[$observationId] = [
                'observation_id' => $observationId,
                'content' => $content,
                'content_hash' => $contentHash,
                'relevance' => $relevance,
                'timestamp' => $timestamp,
                'token_count' => OmTokenEstimator::estimate($content),
                'source_refs_json' => $refsJson,
            ];
            ++$added;
        }

        $error = null;
        $status = 'accepted';
        $message = 'Observations accepted. Continue if uncovered content remains; stop when the chunk is fully covered.';
        if (0 === $added && 0 === $duplicates && $rejected > 0) {
            $status = 'rejected';
            $error = 'all_rejected';
            $message = 'All observations in this call were rejected. Fix timestamps/relevance/source_refs and retry.';
        } elseif ($rejected > 0) {
            $message = 'Some observations were rejected; accepted ones were kept. Fix rejected items and continue.';
        }

        return $this->receipt(
            added: $added,
            duplicates: $duplicates,
            rejected: $rejected,
            status: $status,
            message: $message,
            error: $error,
        );
    }

    /**
     * @return list<array{
     *   observation_id: string,
     *   content: string,
     *   content_hash: string,
     *   relevance: string,
     *   timestamp: string,
     *   token_count: int,
     *   source_refs_json: string
     * }>
     */
    public function collected(): array
    {
        return array_values($this->accepted);
    }

    /**
     * @return array{
     *   status: string,
     *   added: int,
     *   duplicates: int,
     *   rejected: int,
     *   total: int,
     *   message: string,
     *   error?: string
     * }
     */
    private function receipt(
        int $added,
        int $duplicates,
        int $rejected,
        string $status,
        string $message,
        ?string $error,
    ): array {
        $payload = [
            'status' => $status,
            'added' => $added,
            'duplicates' => $duplicates,
            'rejected' => $rejected,
            'total' => \count($this->accepted),
            'message' => $message,
        ];
        if (null !== $error) {
            $payload['error'] = $error;
        }

        return $payload;
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
            $runId = (string) ($ref['run_id'] ?? '');
            $seq = $ref['seq'] ?? null;
            if ('' === $runId || !is_numeric($seq)) {
                throw new \InvalidArgumentException(\sprintf('Observation %d source_refs require run_id and numeric seq.', $index));
            }
            $seqInt = (int) $seq;
            $key = $runId.'|'.$seqInt;
            if (!isset($allowed[$key])) {
                throw new \InvalidArgumentException(\sprintf('Observation %d source_refs cite unknown (run_id, seq)=(%s, %d).', $index, $runId, $seqInt));
            }
            $normalized[] = ['run_id' => $runId, 'seq' => $seqInt];
        }

        return OmIdentity::normalizeSourceRefs($normalized);
    }
}
