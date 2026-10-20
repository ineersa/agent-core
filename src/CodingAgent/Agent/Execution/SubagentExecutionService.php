<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchService;

/**
 * Stable public façade for subagent tool execution.
 *
 * Each tool call defers one child through the normalized durable batch launch
 * service ({@see DeferredSubagentBatchLaunchService}); completion is delivered
 * asynchronously via the generic deferred-tool runtime.
 */
final class SubagentExecutionService
{
    public function __construct(
        private readonly DeferredSubagentBatchLaunchService $deferredBatchLaunch,
    ) {
    }

    /**
     * @throws ToolCallException
     */
    public function execute(
        string $parentRunId,
        string $agentName,
        string $task,
    ): DeferredToolCompletionOutcome {
        return $this->deferredBatchLaunch->launch(
            $parentRunId,
            $agentName,
            $task,
        );
    }
}
