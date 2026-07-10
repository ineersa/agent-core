<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;

/**
 * Durable store for tool batch execution state.
 */
interface ToolBatchStoreInterface
{
    public function load(string $runId, int $turnNo, string $stepId): ?ToolBatchStateDTO;

    public function save(string $runId, int $turnNo, string $stepId, ToolBatchStateDTO $batchState): void;

    public function delete(string $runId, int $turnNo, string $stepId): void;

    public function deleteAllForRun(string $runId): void;

    /**
     * @param callable(?ToolBatchStateDTO): ToolBatchStoreMutation $callback
     */
    public function mutate(string $runId, int $turnNo, string $stepId, callable $callback): mixed;
}
