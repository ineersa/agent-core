<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Compaction;

use Ineersa\Hatfield\ExtensionApi\Tool\ExtensionToolHandlerInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\OmTokenEstimator;
use Ineersa\HatfieldExt\ObservationalMemory\Support\OmIdentity;

/**
 * In-process delta Reflector tool — accumulates new durable reflections only.
 *
 * Multiple valid calls accumulate and dedupe by reflection id. Existing active
 * reflection ids are skipped as duplicates. Persistence is owned by the job after
 * AgentRunner returns. Model-correctable failures return structured rejections.
 */
final class RecordReflectionsToolHandler implements ExtensionToolHandlerInterface
{
    /**
     * @var array<string, array{
     *   reflection_id: string,
     *   content: string,
     *   supporting_observation_ids: list<string>,
     *   supporting_observation_ids_json: string,
     *   token_count: int
     * }>
     */
    private array $newById = [];

    /**
     * @param array<string, true> $existingReflectionIds
     * @param array<string, true> $allowedObservationIds
     */
    public function __construct(
        private readonly string $runId,
        private readonly string $reflectorSchemaVersion,
        private readonly array $existingReflectionIds,
        private readonly array $allowedObservationIds,
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
        if ([] === $rawReflections) {
            return $this->reject(
                'invalid_arguments',
                'record_reflections requires at least one new reflection per call, or do not call the tool.',
            );
        }

        $added = 0;
        $duplicates = 0;
        $rejected = 0;

        foreach ($rawReflections as $index => $raw) {
            if (!\is_array($raw)) {
                ++$rejected;
                continue;
            }

            try {
                $validated = $this->validateNewReflection($raw, $index);
            } catch (\InvalidArgumentException) {
                ++$rejected;
                continue;
            }

            $id = $validated['reflection_id'];
            if (isset($this->existingReflectionIds[$id]) || isset($this->newById[$id])) {
                ++$duplicates;
                continue;
            }
            $this->newById[$id] = $validated;
            ++$added;
        }

        return [
            'status' => 'accepted',
            'added' => $added,
            'duplicates' => $duplicates,
            'rejected' => $rejected,
            'total_this_run' => \count($this->newById),
            'guidance' => \sprintf(
                'Recorded %d reflection%s; %d duplicate%s; %d rejected. Total this run: %d.',
                $added,
                1 === $added ? '' : 's',
                $duplicates,
                1 === $duplicates ? '' : 's',
                $rejected,
                \count($this->newById),
            ),
        ];
    }

    /**
     * @return list<array{
     *   reflection_id: string,
     *   content: string,
     *   supporting_observation_ids: list<string>,
     *   supporting_observation_ids_json: string,
     *   token_count: int
     * }>
     */
    public function newReflections(): array
    {
        return array_values($this->newById);
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
     * @param array<string, mixed> $raw
     *
     * @return array{
     *   reflection_id: string,
     *   content: string,
     *   supporting_observation_ids: list<string>,
     *   supporting_observation_ids_json: string,
     *   token_count: int
     * }
     */
    private function validateNewReflection(array $raw, int $index): array
    {
        if (isset($raw['retain_id']) || isset($raw['retained_observation_ids'])) {
            throw new \InvalidArgumentException(\sprintf('Reflection at index %d must be a new delta reflection (content + supporting_observation_ids only).', $index));
        }

        $content = $raw['content'] ?? null;
        if (!\is_string($content)) {
            throw new \InvalidArgumentException(\sprintf('Reflection at index %d content must be a string.', $index));
        }
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

        $support = $raw['supporting_observation_ids'] ?? null;
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

        return [
            'reflection_id' => $reflectionId,
            'content' => $content,
            'supporting_observation_ids' => $normalizedSupport,
            'supporting_observation_ids_json' => $supportJson,
            'token_count' => OmTokenEstimator::estimate($content),
        ];
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
        if (1 === preg_match('/BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY/i', $content)) {
            return true;
        }

        return 1 === preg_match(
            '/\b(?:api[_-]?key|password|client[_-]?secret|access[_-]?token|auth[_-]?token|bearer\s+token|private[_-]?key)\b\s*[:=]/i',
            $content,
        ) || 1 === preg_match(
            '/\b(?:api[_-]?key|password|client[_-]?secret|access[_-]?token|auth[_-]?token)\b\s+["\']?\S+/i',
            $content,
        );
    }
}
