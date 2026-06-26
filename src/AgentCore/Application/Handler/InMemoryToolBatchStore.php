<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreMutation;

/**
 * In-memory implementation of ToolBatchStoreInterface.
 *
 * Used as default/fallback when no durable store is configured. Also
 * suitable for tests that do not need cross-process persistence.
 */
final class InMemoryToolBatchStore implements ToolBatchStoreInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $batches = [];

    /**
     * @return array<string, mixed>|null
     */
    public function load(string $runId, int $turnNo, string $stepId): ?array
    {
        return $this->batches[$this->key($runId, $turnNo, $stepId)] ?? null;
    }

    /**
     * @param array<string, mixed> $batchState
     */
    public function save(string $runId, int $turnNo, string $stepId, array $batchState): void
    {
        $this->batches[$this->key($runId, $turnNo, $stepId)] = $batchState;
    }

    public function delete(string $runId, int $turnNo, string $stepId): void
    {
        unset($this->batches[$this->key($runId, $turnNo, $stepId)]);
    }

    public function mutate(string $runId, int $turnNo, string $stepId, callable $callback): mixed
    {
        $key = $this->key($runId, $turnNo, $stepId);
        $current = $this->batches[$key] ?? null;
        $outcome = $callback($current);
        if (!$outcome instanceof ToolBatchStoreMutation) {
            throw new \LogicException('Tool batch store mutate callback must return ToolBatchStoreMutation.');
        }

        if (null !== $outcome->nextSerializedState) {
            $this->batches[$key] = $outcome->nextSerializedState;
        }

        return $outcome->returnValue;
    }

    private function key(string $runId, int $turnNo, string $stepId): string
    {
        return \sprintf('%s|%d|%s', $runId, $turnNo, $stepId);
    }
}
