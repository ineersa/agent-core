<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Shell;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Contract\ShellCommandDTO;

/**
 * Creates the canonical event shape shared by in-process and controller shell
 * execution. Direct-shell correlation is carried by the command anchor's ID;
 * lifecycle events do not need a second marker flag.
 */
final class ShellCommandEventFactory
{
    public static function commandApplied(
        string $runId,
        int $turnNo,
        ShellCommandDTO $command,
        string $toolCallId,
        bool $standalone,
    ): RunEvent {
        return new RunEvent(
            runId: $runId,
            seq: 0,
            turnNo: $turnNo,
            type: RunEventTypeEnum::AgentCommandApplied->value,
            payload: [
                'kind' => 'shell_command',
                'text' => $command->originalText,
                'command' => $command->commandText,
                'tool_call_id' => $toolCallId,
                'standalone' => $standalone,
                'idempotency_key' => hash('sha256', $runId.'|'.$toolCallId.'|shell_command'),
            ],
        );
    }

    public static function executionStarted(string $runId, int $turnNo, string $toolCallId): RunEvent
    {
        return new RunEvent(
            runId: $runId,
            seq: 0,
            turnNo: $turnNo,
            type: RunEventTypeEnum::ToolExecutionStart->value,
            payload: [
                'tool_call_id' => $toolCallId,
                'tool_name' => 'bash',
                'order_index' => 0,
            ],
        );
    }

    public static function executionCompleted(
        string $runId,
        int $turnNo,
        string $toolCallId,
        bool $isError,
        string $result,
    ): RunEvent {
        return new RunEvent(
            runId: $runId,
            seq: 0,
            turnNo: $turnNo,
            type: RunEventTypeEnum::ToolExecutionEnd->value,
            payload: [
                'tool_call_id' => $toolCallId,
                'is_error' => $isError,
                'result' => $result,
            ],
        );
    }
}
