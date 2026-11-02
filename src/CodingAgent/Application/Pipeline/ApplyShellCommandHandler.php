<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Pipeline\RunMessageHandler;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\ApplyShellCommand;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Run\CurrentOperationDTO;
use Ineersa\AgentCore\Domain\Run\CurrentToolCallDTO;
use Ineersa\AgentCore\Domain\Run\RunOperationalToolCallStatusEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Run\ToolBatchIdentity;
use Ineersa\CodingAgent\Session\Contract\RunHistorySourceProviderInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Applies a direct shell command under the same lock/CAS/commit pipeline as
 * every other run command.
 *
 * A shell command does not become model context. Its AgentCommandApplied event
 * is the transcript anchor. Canonical tool_execution_start is committed with
 * the command under the owner lock before ExecuteShellToolCall is dispatched.
 * The execution worker runs bash and posts ToolCallResult only.
 */
final readonly class ApplyShellCommandHandler implements RunMessageHandler
{
    public function __construct(
        private EventFactory $eventFactory,
        private EventStoreInterface $eventStore,
        private NormalizerInterface $normalizer,
        private RunHistorySourceProviderInterface $sources,
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

        // The disposable index rules out fresh identities without scanning the
        // archive. Canonical evidence still authorizes duplicate suppression.
        if ($this->wasApplied($state->runId, $message->idempotencyKey())) {
            return new HandlerResult();
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
        $standalone = RunStatus::Queued === $state->status || $terminalBoundary;
        $commandTurnNo = $state->turnNo;
        $owningTurnNo = $startsChildTurn
            ? max($state->lastSeq, $state->turnNo) + 1
            : $state->turnNo;
        $operation = new CurrentOperationDTO(
            $owningTurnNo,
            $message->stepId(),
            $message->attempt(),
            $message->idempotencyKey(),
        );
        $normalizedOperation = $this->normalizer->normalize($operation);
        if (!\is_array($normalizedOperation)) {
            throw new \LogicException('Current operation normalization must produce an array.');
        }

        $eventSpecs = [[
            'type' => RunEventTypeEnum::AgentCommandApplied->value,
            'payload' => [
                'kind' => 'shell_command',
                'idempotency_key' => $message->idempotencyKey(),
                'text' => $rawInput,
                'options' => [],
                // Repair replays canonical events. Keep
                // every shell execution self-describing without retaining a
                // receipt history or adding a separate storage record.
                'standalone' => $standalone,
                'current_operation' => $normalizedOperation,
            ],
        ]];

        if ($startsChildTurn) {
            // RunMessageProcessor serializes this under the run lock. lastSeq is
            // the global canonical high-water so discarded turns cannot collide.
            // AgentCommandApplied stays on the prior tip so the command-to-next-
            // TurnAdvanced map treats this shell as a seeder.
            $previousTurnNo = $state->turnNo;
            $eventSpecs[] = [
                'type' => RunEventTypeEnum::TurnAdvanced->value,
                'turn_no' => $owningTurnNo,
                'payload' => [
                    'step_id' => $message->stepId(),
                    'turn_no' => $owningTurnNo,
                    'operation_attempt' => $message->attempt(),
                    'operation_idempotency_key' => $message->idempotencyKey(),
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

        // Lifecycle acceptance before external work, matching ordinary
        // LlmStepResultHandler tool starts. Arguments preserve the flat bash
        // command shape transcript/TUI already consume. This is not subprocess
        // start timing; duration remains on the later ToolCallResult.
        $eventSpecs[] = [
            'type' => RunEventTypeEnum::ToolExecutionStart->value,
            'turn_no' => $owningTurnNo,
            'payload' => [
                'tool_call_id' => $toolCallId,
                'tool_name' => 'bash',
                'order_index' => 0,
                'attempt' => $message->attempt(),
                'arguments' => ['command' => $shellText],
            ],
        ];

        $events = $this->eventFactory->eventsFromSpecs(
            $state->runId,
            $commandTurnNo,
            $state->lastSeq + 1,
            $eventSpecs,
        );

        // Deterministic tool-call id keeps Messenger retries from creating a
        // second bash lifecycle for the same ApplyShellCommand message.
        $effect = new ExecuteShellToolCall(
            runId: $state->runId,
            turnNo: $owningTurnNo,
            stepId: $message->stepId(),
            attempt: $message->attempt(),
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
            'currentOperation' => $standalone ? $operation : $state->currentOperation,
            'pendingShellToolCalls' => [...$state->pendingShellToolCalls, $toolCallId => true],
            'currentToolCalls' => [...$state->currentToolCalls, new CurrentToolCallDTO(
                ToolBatchIdentity::fromTurnAndStep($owningTurnNo, $message->stepId()),
                $toolCallId,
                0,
                RunOperationalToolCallStatusEnum::Running,
                $message->attempt(),
            )],
        ]);

        return new HandlerResult(
            nextState: $nextState,
            events: $events,
            effects: [$effect],
        );
    }

    private function wasApplied(string $runId, string $key): bool
    {
        $source = $this->sources->historySource($runId);
        if (!is_file($source->path)) {
            return false;
        }
        $cut = $source->log->historyCut($source->path, $runId);
        $sequence = $source->log->latestCommandSequences($source->path, $runId, [$key], $cut['sequence'] ?? 0)[$key] ?? null;
        if (null === $sequence) {
            return false;
        }
        $indexed = null;
        foreach ($this->eventStore->rangeFor($runId, $sequence, $sequence) as $event) {
            if (null !== $indexed || $event->seq !== $sequence || $event->runId !== $runId
                || ($event->payload['idempotency_key'] ?? null) !== $key) {
                throw new \RuntimeException('Indexed shell command evidence does not match canonical history.');
            }
            $indexed = $event;
        }
        unset($event);
        if (null === $indexed) {
            throw new \RuntimeException('Indexed shell command evidence is missing from canonical history.');
        }
        if ($this->matches($indexed, $key)) {
            return true;
        }
        // Identity-only indexing must not hide an older applied shell behind
        // a later command of another kind/status using the same key.
        foreach ($this->eventStore->reverseFor($runId) as $event) {
            if ($event->seq < $sequence && $this->matches($event, $key)) {
                return true;
            }
        }

        return false;
    }

    private function matches(RunEvent $event, string $key): bool
    {
        return RunEventTypeEnum::AgentCommandApplied->value === $event->type
            && 'shell_command' === ($event->payload['kind'] ?? null)
            && $key === ($event->payload['idempotency_key'] ?? null);
    }
}
