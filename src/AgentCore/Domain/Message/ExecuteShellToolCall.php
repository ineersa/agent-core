<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Executes a user-initiated shell command in the tool consumer process.
 *
 * The owning turn is assigned by ApplyShellCommandHandler while processing the
 * command under the run lock. The worker uses that turn for tool lifecycle
 * events and may append AgentEnd when the shell action is standalone.
 */
final readonly class ExecuteShellToolCall extends AbstractAgentBusMessage
{
    public function __construct(
        string $runId,
        int $turnNo,
        public string $toolCallId,
        public string $commandText,
        public bool $standalone,
    ) {
        parent::__construct(
            runId: $runId,
            turnNo: $turnNo,
            stepId: '',
            attempt: 1,
            idempotencyKey: hash('sha256', $runId.'|'.$toolCallId),
        );
    }
}
