<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch;

use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchPreparationFailure;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;

/**
 * Kind-specific deferred batch preparation (plan build, pending prepare, launched index collection).
 */
interface DeferredAgentChildBatchPreparationInterface
{
    /**
     * @param list<AgentChildLaunchTaskInterface> $tasks
     */
    public function buildLaunchPlan(
        string $parentRunId,
        string $toolCallId,
        array $tasks,
        ChildRunBatchExecutionModeEnum $executionMode,
    ): DeferredAgentChildBatchLaunchPlanDTO;

    /**
     * @return list<PreparedAgentChildRunDTO>
     *
     * @throws DeferredSubagentBatchPreparationFailure
     */
    public function preparePendingChildren(
        string $parentRunId,
        DeferredSubagentBatchProjectionDTO $projection,
        DeferredAgentChildBatchLaunchPlanDTO $plan,
    ): array;

    /**
     * @param list<PreparedAgentChildRunDTO> $preparedChildren
     *
     * @return list<int>
     */
    public function collectLaunchedBatchIndices(
        string $parentRunId,
        DeferredSubagentBatchProjectionDTO $projection,
        DeferredAgentChildBatchLaunchPlanDTO $plan,
        array $preparedChildren,
    ): array;
}
