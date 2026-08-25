<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\ApplyShellCommand;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Run\CurrentOperationDTO;
use Ineersa\AgentCore\Domain\Run\CurrentOperationKindEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;

/**
 * Applies a direct shell command under the same lock/CAS/commit pipeline as
 * every other run command.
 *
 * A shell command does not become model context. Its AgentCommandApplied event
 * is the transcript anchor; the execution effect owns the tool lifecycle.
 */
final readonly class ApplyShellCommandHandler implements RunMessageHandler
{
    public function __construct(
        private EventFactory $eventFactory,
    ) {
    }

    public function supports(object $message): bool
    {
        return $message instanceof ApplyShellCommand;
    }

    public function handle(object $message, RunState $state): HandlerResult
    {
        if (!$message instanceof ApplyShellCommand) {
            throw new \InvalidArgumentException('ApplyShellCommandHandler can only handle ApplyShellCommand messages.');
        }

        // Direct shells can attach to an active LLM, so currentOperation cannot
        // represent them without invalidating that LLM result. Keep only the
        // currently executing deterministic tool ids; this is removed by the
        // canonical tool_execution_end reducer, never retained as a receipt.
        $toolCallId = 'sh_'.hash('sha256', $message->idempotencyKey());
        if (isset($state->pendingShellToolCalls[$toolCallId])) {
            return new HandlerResult();
        }

        $rawInput = $message->rawInput;
        if (!str_starts_with($rawInput, '!')) {
            throw new \InvalidArgumentException('Shell command raw input must start with "!".');
        }
        if (str_starts_with($rawInput, '!!')) {
            throw new \InvalidArgumentException('!! is not supported. Use ! to execute shell commands.');
        }

        // Executable text is derived only for the tool effect. Canonical
        // agent_command_applied stores the exact raw bang input for transcript
        // projection; dual commandText/originalText representations are forbidden.
        $shellText = ltrim(substr($rawInput, 1));
        if ('' === $shellText) {
            throw new \InvalidArgumentException('Shell command must contain a non-empty command after "!".');
        }

        // Ownership:
        // - pre-conversation (turnNo=0): shell remains run-level turn 0
        // - active non-terminal run: shell owns the current turn
        // - terminal conversational run: shell seeds a new active turn so
        //   history filtering can exclude it after a later tip reposition/discard
        $hasConversationalTurn = $state->turnNo > 0;
        $terminalBoundary = $state->status->isTerminal();
        $startsChildTurn = $terminalBoundary && $hasConversationalTurn;
        $commandTurnNo = $state->turnNo;
        $owningTurnNo = $state->turnNo;
        $eventSpecs = [[
            'type' => RunEventTypeEnum::AgentCommandApplied->value,
            'payload' => [
                'kind' => 'shell_command',
                'idempotency_key' => $message->idempotencyKey(),
                'text' => $rawInput,
                'options' => [],
            ],
        ]];

        if ($startsChildTurn) {
            // RunMessageProcessor serializes this under the run lock. lastSeq is
            // the global canonical high-water so discarded turns cannot collide.
            // AgentCommandApplied stays on the prior tip so the command-to-next-
            // TurnAdvanced map treats this shell as a seeder.
            $owningTurnNo = max($state->lastSeq, $state->turnNo) + 1;
            $previousTurnNo = $state->turnNo;
            $eventSpecs[] = [
                'type' => RunEventTypeEnum::TurnAdvanced->value,
                'turn_no' => $owningTurnNo,
                'payload' => [
                    'step_id' => $message->stepId(),
                    'turn_no' => $owningTurnNo,
                ],
            ];
            $eventSpecs[] = [
                'type' => RunEventTypeEnum::HistoryPositionSet->value,
                'turn_no' => $owningTurnNo,
                'payload' => [
                    'position_turn_no' => $owningTurnNo,
                    'previous_position_turn_no' => $previousTurnNo,
                    'reason' => 'shell_command',
                ],
            ];
        }

        $events = $this->eventFactory->eventsFromSpecs(
            $state->runId,
            $commandTurnNo,
            $state->lastSeq + 1,
            $eventSpecs,
        );

        // Standalone means the shell action owns terminalization. Queued and
        // terminal runs have no in-flight model turn that will emit AgentEnd;
        // the worker therefore writes AgentEnd after tool lifecycle events.
        // Active running shells attach to the current turn and leave run
        // terminalization to the model turn.
        $standalone = RunStatus::Queued === $state->status || $terminalBoundary;

        // Deterministic tool-call id keeps Messenger retries from creating a
        // second bash lifecycle for the same ApplyShellCommand message.
        $effect = new ExecuteShellToolCall(
            runId: $state->runId,
            turnNo: $owningTurnNo,
            toolCallId: $toolCallId,
            commandText: $shellText,
            standalone: $standalone,
        );

        $nextStatus = $startsChildTurn || RunStatus::Queued === $state->status
            ? RunStatus::Running
            : $state->status;
        $nextState = $state->with([
            'status' => $nextStatus,
            'version' => $state->version + 1,
            'turnNo' => $owningTurnNo,
            'lastSeq' => $state->lastSeq + \count($events),
            'errorMessage' => $startsChildTurn ? null : $state->errorMessage,
            'activeStepId' => $startsChildTurn || RunStatus::Queued === $state->status
                ? $message->stepId()
                : $state->activeStepId,
            // An attached shell must not replace the active LLM token: its
            // result still authorizes the in-flight LLM completion. Standalone
            // shells have no competing model operation and are recoverable by
            // this bounded descriptor.
            'currentOperation' => $startsChildTurn || RunStatus::Queued === $state->status
                ? new CurrentOperationDTO(
                    CurrentOperationKindEnum::Shell,
                    $owningTurnNo,
                    $message->stepId(),
                    $message->attempt(),
                    $message->idempotencyKey(),
                    $toolCallId,
                )
                : $state->currentOperation,
            'pendingShellToolCalls' => [...$state->pendingShellToolCalls, $toolCallId => true],
            'retryableFailure' => $startsChildTurn ? false : $state->retryableFailure,
            // A child turn starts a fresh retry episode; an in-place shell on
            // an active run keeps the episode counter intact.
            'retryAttempts' => $startsChildTurn ? 0 : $state->retryAttempts,
        ]);

        return new HandlerResult(
            nextState: $nextState,
            events: $events,
            effects: [$effect],
        );
    }
}
