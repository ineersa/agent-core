<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\CodingAgent\Agent\Execution\ChildRun\ForegroundAgentChildRunSupervisor;
use Ineersa\CodingAgent\Config\AgentsConfig;

/**
 * Stable public façade for foreground subagent tool execution.
 *
 * Single-child runs delegate to {@see ForegroundAgentChildRunSupervisor} after
 * {@see SubagentLaunchPreparationService} builds a typed {@see ChildRun\PreparedAgentChildRunDTO}.
 * Parallel runs delegate to {@see ParallelSubagentExecutionService}.
 *
 * The typed child lifecycle in ChildRun/ is the reusable backend for future child kinds
 * (same supervision, progress, and finalization boundaries; subagent-specific preparation stays separate).
 */
final class SubagentExecutionService
{
    public function __construct(
        private readonly SubagentLaunchPreparationService $launchPreparation,
        private readonly ForegroundAgentChildRunSupervisor $childRunSupervisor,
        private readonly ParallelSubagentExecutionService $parallelExecution,
        private readonly AgentsConfig $agentsConfig,
    ) {
    }

    /**
     * Execute a single foreground subagent run.
     */
    public function execute(
        string $parentRunId,
        string $agentName,
        string $task,
    ): string {
        $prepared = $this->launchPreparation->prepareSingle($parentRunId, $agentName, $task);

        return $this->childRunSupervisor->superviseUntilTerminal(
            $prepared,
            $this->agentsConfig->subagentToolTimeoutSeconds,
        );
    }

    /**
     * Execute multiple foreground subagents in parallel (one tool call).
     *
     * @param list<SubagentTaskDTO> $tasks
     */
    public function executeParallel(string $parentRunId, array $tasks): string
    {
        return $this->parallelExecution->execute($parentRunId, $tasks);
    }
}
