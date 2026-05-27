<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;

/**
 * In-memory implementation of ToolBatchStoreInterface.
 *
 * Used as default/fallback when no durable store is configured. Also
 * suitable for tests that do not need cross-process persistence.
 */
final class InMemoryToolBatchStore implements ToolBatchStoreInterface
{
    /** @var array<string, array> */
    private array $batches = [];

    public function load(string $runId, int $turnNo, string $stepId): ?array
    {
        return $this->batches[$this->key($runId, $turnNo, $stepId)] ?? null;
    }

    public function save(string $runId, int $turnNo, string $stepId, array $batchState): void
    {
        $this->batches[$this->key($runId, $turnNo, $stepId)] = $batchState;
    }

    public function delete(string $runId, int $turnNo, string $stepId): void
    {
        unset($this->batches[$this->key($runId, $turnNo, $stepId)]);
    }

    private function key(string $runId, int $turnNo, string $stepId): string
    {
        return \sprintf('%s|%d|%s', $runId, $turnNo, $stepId);
    }
}
