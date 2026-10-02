<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Executes a user-initiated shell command in the tool consumer process.
 *
 * The owning turn is assigned by ApplyShellCommandHandler while processing the
 * command under the run lock. That handler also commits tool_execution_start
 * before dispatching this effect. The worker only executes bash and posts
 * ToolCallResult; run_control commits completion and standalone AgentEnd.
 */
final readonly class ExecuteShellToolCall extends AbstractAgentBusMessage
{
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        public string $toolCallId,
        public string $commandText,
        public bool $standalone,
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, hash('sha256', $runId.'|'.$toolCallId));
    }
}
