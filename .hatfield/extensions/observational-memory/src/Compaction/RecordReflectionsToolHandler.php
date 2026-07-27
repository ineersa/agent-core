<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Compaction;

use Ineersa\Hatfield\ExtensionApi\Tool\ExtensionToolHandlerInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\OmTokenEstimator;
use Ineersa\HatfieldExt\ObservationalMemory\Support\OmIdentity;

/**
 * In-process tool that validates complete Reflector candidate generations.
 *
 * Each valid call atomically replaces the in-memory candidate. Persistence is
 * owned by ReflectorPipeline after AgentRunner returns. Model-correctable
 * validation failures return structured rejections without mutating state.
 */
final class RecordReflectionsToolHandler implements ExtensionToolHandlerInterface
{
    private bool $hasCandidate = false;

    /**
     * @var list<array{
     *   reflection_id: string,
     *   content: string,
     *   supporting_observation_ids: list<string>,
     *   supporting_observation_ids_json: string,
     *   token_count: int,
     *   retained: bool
     * }>
     */
    private array $reflections = [];

    /** @var list<string> */
    private array $retainedObservationIds = [];

    /**
     * @param array<string, true> $allowedReflectionIds
     * @param array<string, true> $allowedObservationIds
     * @param array<string, array{
     *   reflection_id: string,
     *   content: string,
     *   supporting_observation_ids: list<string>,
     *   supporting_observation_ids_json: string,
     *   token_count: int
     * }> $activeReflectionsById
     */
    public function __construct(
        private readonly string $runId,
        private readonly string $reflectorSchemaVersion,
        private readonly array $allowedReflectionIds,
        private readonly array $allowedObservationIds,
        private readonly array $activeReflectionsById,
        private readonly bool $requireNonEmptyOutput,
    ) {
    }

    public function __invoke(array $arguments): mixed
    {
        $rawReflections = $arguments['reflections'] ?? null;
        if (!\is_array($rawReflections)) {
            return $this->reject(
                'invalid_arguments',
                'record_reflections requires reflections as a list (array).',
            );
        }

        $rawRetained = $arguments['retained_observation_ids'] ?? $arguments['retainedObservationIds'] ?? null;
        if (!\is_array($rawRetained)) {
            return $this->reject(
                'invalid_arguments',
                'record_reflections requires retained_observation_ids as a list (array).',
            );
        }

        if ($this->requireNonEmptyOutput && [] === $rawReflections && [] === $rawRetained) {
            return $this->reject(
                'empty_generation',
                'When active memory is non-empty, record_reflections must retain reflections and/or observations.',
            );
        }

        try {
            $validatedReflections = $this->validateReflections($rawReflections);
            $retainedObservationIds = $this->normalizeObservationIds($rawRetained, 'retained_observation_ids');
        } catch (\InvalidArgumentException $e) {
            return $this->reject('invalid_generation', $e->getMessage());
        }

        // Atomically replace previous candidate (last valid wins).
        $this->reflections = $validatedReflections;
        $this->retainedObservationIds = $retainedObservationIds;
        $this->hasCandidate = true;

        return [
            'status' => 'accepted',
            'reflection_count' => \count($validatedReflections),
            'retained_observation_count' => \count($retainedObservationIds),
            'guidance' => 'Candidate generation replaced. You may call record_reflections again to revise the complete next active set before finishing.',
        ];
    }

    public function hasCandidate(): bool
    {
        return $this->hasCandidate;
    }

    /**
     * @return list<array{
     *   reflection_id: string,
     *   content: string,
     *   supporting_observation_ids: list<string>,
     *   supporting_observation_ids_json: string,
     *   token_count: int,
     *   retained: bool
     * }>
     */
    public function reflections(): array
    {
        return $this->reflections;
    }

    /**
     * @return list<string>
     */
    public function retainedObservationIds(): array
    {
        return $this->retainedObservationIds;
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
     * @param list<mixed> $rawReflections
     *
     * @return list<array{
     *   reflection_id: string,
     *   content: string,
     *   supporting_observation_ids: list<string>,
     *   supporting_observation_ids_json: string,
     *   token_count: int,
     *   retained: bool
     * }>
     */
    private function validateReflections(array $rawReflections): array
    {
        $out = [];
        $seenReflectionIds = [];

        foreach ($rawReflections as $index => $raw) {
            if (!\is_array($raw)) {
                throw new \InvalidArgumentException(\sprintf('Reflection at index %d must be an object.', $index));
            }

            $retainId = $raw['retain_id'] ?? $raw['retainId'] ?? null;
            $content = $raw['content'] ?? null;
            $support = $raw['supporting_observation_ids'] ?? $raw['supportingObservationIds'] ?? null;

            $hasRetain = null !== $retainId;
            $hasNew = null !== $content || null !== $support;
            if ($hasRetain && $hasNew) {
                throw new \InvalidArgumentException(\sprintf('Reflection at index %d must be either retain_id or new content/supporting_observation_ids, not both.', $index));
            }
            if (!$hasRetain && !$hasNew) {
                throw new \InvalidArgumentException(\sprintf('Reflection at index %d must include retain_id or content+supporting_observation_ids.', $index));
            }

            if ($hasRetain) {
                if (!\is_string($retainId) || '' === trim($retainId)) {
                    throw new \InvalidArgumentException(\sprintf('Reflection at index %d retain_id must be a non-empty string.', $index));
                }
                $retainId = trim($retainId);
                if (!isset($this->allowedReflectionIds[$retainId])) {
                    throw new \InvalidArgumentException(\sprintf('Reflection at index %d retain_id %s is not in the active reflection allowlist.', $index, $retainId));
                }
                if (isset($seenReflectionIds[$retainId])) {
                    continue;
                }
                $prior = $this->activeReflectionsById[$retainId] ?? null;
                if (null === $prior) {
                    throw new \InvalidArgumentException(\sprintf('Reflection at index %d retain_id %s is unknown.', $index, $retainId));
                }
                $seenReflectionIds[$retainId] = true;
                $out[] = [
                    'reflection_id' => $prior['reflection_id'],
                    'content' => $prior['content'],
                    'supporting_observation_ids' => $prior['supporting_observation_ids'],
                    'supporting_observation_ids_json' => $prior['supporting_observation_ids_json'],
                    'token_count' => $prior['token_count'],
                    'retained' => true,
                ];
                continue;
            }

            if (!\is_string($content)) {
                throw new \InvalidArgumentException(\sprintf('Reflection at index %d content must be a string.', $index));
            }
            // Trim outer whitespace only; otherwise byte-preserve single-line content.
            $content = trim($content);
            if ('' === $content) {
                throw new \InvalidArgumentException(\sprintf('Reflection at index %d content is empty after trim.', $index));
            }
            if (str_contains($content, "\n") || str_contains($content, "\r")) {
                throw new \InvalidArgumentException(\sprintf('Reflection at index %d content must be a single line.', $index));
            }
            if ($this->looksLikeSecret($content)) {
                throw new \InvalidArgumentException(\sprintf('Reflection at index %d appears to contain secrets and was rejected.', $index));
            }
            if (!\is_array($support) || [] === $support) {
                throw new \InvalidArgumentException(\sprintf('Reflection at index %d must include non-empty supporting_observation_ids.', $index));
            }

            $normalizedSupport = $this->normalizeObservationIds($support, \sprintf('reflection[%d].supporting_observation_ids', $index));
            try {
                $supportJson = json_encode(
                    $normalizedSupport,
                    \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
                );
            } catch (\JsonException $e) {
                throw new \RuntimeException('Failed to encode supporting_observation_ids.', previous: $e);
            }

            $reflectionId = OmIdentity::reflectionId(
                $this->runId,
                $this->reflectorSchemaVersion,
                $content,
                $normalizedSupport,
            );
            if (isset($seenReflectionIds[$reflectionId])) {
                continue;
            }
            $seenReflectionIds[$reflectionId] = true;

            $out[] = [
                'reflection_id' => $reflectionId,
                'content' => $content,
                'supporting_observation_ids' => $normalizedSupport,
                'supporting_observation_ids_json' => $supportJson,
                'token_count' => OmTokenEstimator::estimate($content),
                'retained' => false,
            ];
        }

        return $out;
    }

    /**
     * @param list<mixed> $ids
     *
     * @return list<string>
     */
    private function normalizeObservationIds(array $ids, string $label): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            if (!\is_string($id) || '' === trim($id)) {
                throw new \InvalidArgumentException(\sprintf('%s must contain non-empty strings only.', $label));
            }
            $id = trim($id);
            if (!isset($this->allowedObservationIds[$id])) {
                throw new \InvalidArgumentException(\sprintf('%s cites unknown observation_id %s.', $label, $id));
            }
            $normalized[] = $id;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, \SORT_STRING);

        return $normalized;
    }

    private function looksLikeSecret(string $content): bool
    {
        return 1 === preg_match(
            '/\b(api[_-]?key|password|secret|token|private[_-]?key|BEGIN (RSA |OPENSSH |EC )?PRIVATE KEY)\b/i',
            $content,
        );
    }
}
