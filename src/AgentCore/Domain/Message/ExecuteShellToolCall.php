<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Dispatched by ShellCommandHandler on the agent.execution.bus to execute
 * a user-initiated shell command (! prefix) in the tool consumer process
 * rather than synchronously in the controller.
 *
 * Executes shell work in the tool consumer so the controller event loop stays
 * free to project runtime events (including Path A human_input.requested) and
 * accept answer_human while tool execution is in flight.
 *
 * Routing: agent.execution.bus → tool transport (messenger:consume tool).
 * The worker (ExecuteShellToolCallWorker) executes bash via the shared
 * ToolExecutor and writes canonical tool_execution_start / tool_execution_end
 * events to the EventStore so the TUI poller surfaces shell output.
 */
final readonly class ExecuteShellToolCall extends AbstractAgentBusMessage
{
    /**
     * @param string $runId       The run/session ID
     * @param string $toolCallId  Unique tool-call identifier for idempotency and dedup
     * @param string $commandText The shell command text to execute via bash
     * @param bool   $standalone  when true, the worker owns the terminal AgentEnd
     *                            event so that tool_exec_start/end and AgentEnd are
     *                            written by a single process in guaranteed order —
     *                            avoids the ordering race where AgentEnd appeared
     *                            before tool_exec events (issue #183).  Set by
     *                            the standalone (first-input) shellExecute() path.
     */
    public function __construct(
        string $runId,
        public string $toolCallId,
        public string $commandText,
        public bool $standalone = false,
    ) {
        parent::__construct(
            runId: $runId,
            turnNo: 0,
            stepId: '',
            attempt: 1,
            idempotencyKey: hash('sha256', $runId.'|'.$toolCallId),
        );
    }
}
