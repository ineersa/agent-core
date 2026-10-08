<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;

/** Active batch calls, collected results, and captured scheduling decisions. */
interface ToolBatchStoreInterface
{
    public function load(string $runId, int $turnNo, string $stepId): ?ToolBatchStateDTO;

    public function delete(string $runId, int $turnNo, string $stepId): void;

    public function deleteAllForRun(string $runId): void;

    public function hasUnresolvedExecution(string $runId, ?string $toolCallId = null): bool;

    /**
     * Prepare payloads before the metadata transaction. The returned local write
     * joins that transaction; it is never persisted as transition work.
     *
     * @param list<RegisterToolBatchDTO|FinalizeToolBatchDTO> $actions
     *
     * @return \Closure(): void
     */
    public function prepareChanges(array $actions, VerifiedTransitionDTO $transition): \Closure;
}
