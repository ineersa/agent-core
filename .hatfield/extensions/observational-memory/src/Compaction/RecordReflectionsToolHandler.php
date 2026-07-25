<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Compaction;

use Ineersa\Hatfield\ExtensionApi\Tool\ExtensionToolHandlerInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\OmTokenEstimator;

/**
 * In-process tool that validates Reflector replacement text + reflections.
 *
 * Persistence happens after AgentRunner returns. Model-correctable validation
 * failures return structured rejections. First valid invocation wins.
 */
final class RecordReflectionsToolHandler implements ExtensionToolHandlerInterface
{
    private bool $recorded = false;

    private ?string $replacementText = null;

    /**
     * @var list<array{
     *   reflection_id: string,
     *   content: string,
     *   supporting_observation_ids_json: string,
     *   compression_level: string,
     *   token_count: int
     * }>
     */
    private array $reflections = [];

    /**
     * @param array<string, true> $allowedObservationIds
     */
    public function __construct(
        private readonly string $runId,
        private readonly string $requestId,
        private readonly string $reflectorSchemaVersion,
        private readonly int $compressionLevel,
        private readonly array $allowedObservationIds,
        private readonly int $maxReflections,
        private readonly int $reflectionContentMaxChars,
        private readonly int $replacementMaxChars,
        private readonly int $reflectionsMaxTokens,
    ) {
    }

    public function __invoke(array $arguments): mixed
    {
        if ($this->recorded) {
            return $this->reject(
                'already_recorded',
                'record_reflections already accepted one invocation for this request; do not call it again.',
            );
        }

        $replacement = $arguments['replacement_text'] ?? $arguments['replacementText'] ?? null;
        if (!\is_string($replacement) || '' === trim($replacement)) {
            return $this->reject(
                'invalid_replacement_text',
                'record_reflections requires non-empty replacement_text.',
            );
        }
        $replacement = trim($replacement);
        if (mb_strlen($replacement, 'UTF-8') > $this->replacementMaxChars) {
            return $this->reject(
                'replacement_too_long',
                \sprintf('replacement_text exceeds %d characters.', $this->replacementMaxChars),
            );
        }

        $reflections = $arguments['reflections'] ?? null;
        if (!\is_array($reflections)) {
            return $this->reject(
                'invalid_arguments',
                'record_reflections requires reflections as a list (array).',
            );
        }
        // Reflector success requires at least one durable reflection when
        // observations were non-empty (worker only invokes Reflector then).
        // Empty list is model-correctable; do not mutate recorded state.
        if ([] === $reflections) {
            return $this->reject(
                'empty_reflections',
                'record_reflections requires at least one reflection.',
            );
        }
        if (\count($reflections) > $this->maxReflections) {
            return $this->reject(
                'too_many_reflections',
                \sprintf('record_reflections exceeded max reflections (%d > %d).', \count($reflections), $this->maxReflections),
            );
        }

        $validated = [];
        $tokenTotal = 0;
        $seen = [];
        foreach ($reflections as $index => $raw) {
            if (!\is_array($raw)) {
                return $this->reject('invalid_reflection', \sprintf('Reflection at index %d must be an object.', $index));
            }

            $content = $raw['content'] ?? null;
            if (!\is_string($content) || '' === trim($content)) {
                return $this->reject('invalid_content', \sprintf('Reflection at index %d must have non-empty content.', $index));
            }
            $content = trim($content);
            if (mb_strlen($content, 'UTF-8') > $this->reflectionContentMaxChars) {
                return $this->reject(
                    'content_too_long',
                    \sprintf('Reflection at index %d content exceeds %d characters.', $index, $this->reflectionContentMaxChars),
                );
            }

            $level = $raw['compression_level'] ?? $raw['compressionLevel'] ?? null;
            if (!is_numeric($level)) {
                return $this->reject('invalid_compression_level', \sprintf('Reflection at index %d compression_level must be 0 or 1.', $index));
            }
            $levelInt = (int) $level;
            if (!\in_array($levelInt, [0, 1], true)) {
                return $this->reject('invalid_compression_level', \sprintf('Reflection at index %d compression_level must be 0 or 1.', $index));
            }
            // Worker chose the pool pressure level for this request; model must not invent another.
            if ($levelInt !== $this->compressionLevel) {
                return $this->reject(
                    'compression_level_mismatch',
                    \sprintf(
                        'Reflection at index %d compression_level must be %d for this request.',
                        $index,
                        $this->compressionLevel,
                    ),
                );
            }

            $support = $raw['supporting_observation_ids'] ?? $raw['supportingObservationIds'] ?? [];
            if (!\is_array($support) || [] === $support) {
                return $this->reject(
                    'missing_supporting_observation_ids',
                    \sprintf('Reflection at index %d must cite supporting_observation_ids.', $index),
                );
            }

            try {
                $normalizedSupport = $this->normalizeSupportIds($support, $index);
                $supportJson = json_encode($normalizedSupport, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            } catch (\InvalidArgumentException $e) {
                return $this->reject('invalid_supporting_observation_ids', $e->getMessage());
            } catch (\JsonException $e) {
                throw new \RuntimeException('Failed to encode supporting_observation_ids.', previous: $e);
            }

            $tokenCount = OmTokenEstimator::estimate($content);

            // Dedupe before budget accounting so identical records do not consume the pool twice.
            $dedupeKey = hash('sha256', $content).'|'.$supportJson.'|'.$levelInt;
            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $tokenTotal += $tokenCount;
            if ($tokenTotal > $this->reflectionsMaxTokens) {
                return $this->reject(
                    'reflections_token_budget_exceeded',
                    \sprintf('Reflections exceed token budget (%d > %d).', $tokenTotal, $this->reflectionsMaxTokens),
                );
            }

            $seen[$dedupeKey] = true;

            $reflectionId = hash('sha256', implode('|', [
                $this->runId,
                $this->requestId,
                $this->reflectorSchemaVersion,
                (string) $levelInt,
                hash('sha256', $content),
                $supportJson,
            ]));

            $validated[] = [
                'reflection_id' => $reflectionId,
                'content' => $content,
                'supporting_observation_ids_json' => $supportJson,
                'compression_level' => (string) $levelInt,
                'token_count' => $tokenCount,
            ];
        }

        $this->replacementText = $replacement;
        $this->reflections = $validated;
        $this->recorded = true;

        return [
            'status' => 'accepted',
            'reflection_count' => \count($validated),
            'replacement_chars' => mb_strlen($replacement, 'UTF-8'),
        ];
    }

    public function hasRecorded(): bool
    {
        return $this->recorded;
    }

    public function replacementText(): ?string
    {
        return $this->replacementText;
    }

    /**
     * @return list<array{
     *   reflection_id: string,
     *   content: string,
     *   supporting_observation_ids_json: string,
     *   compression_level: string,
     *   token_count: int
     * }>
     */
    public function reflections(): array
    {
        return $this->reflections;
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
     * @param list<mixed> $support
     *
     * @return list<string>
     */
    private function normalizeSupportIds(array $support, int $index): array
    {
        $normalized = [];
        foreach ($support as $id) {
            if (!\is_string($id) || '' === trim($id)) {
                throw new \InvalidArgumentException(\sprintf('Reflection %d supporting_observation_ids must be non-empty strings.', $index));
            }
            $id = trim($id);
            if (!isset($this->allowedObservationIds[$id])) {
                throw new \InvalidArgumentException(\sprintf('Reflection %d cites unknown observation_id %s.', $index, $id));
            }
            $normalized[] = $id;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, \SORT_STRING);

        return $normalized;
    }
}
