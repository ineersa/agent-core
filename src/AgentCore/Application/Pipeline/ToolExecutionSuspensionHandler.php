<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\ToolExecutionSuspension;
use Ineersa\AgentCore\Domain\Run\HumanInputContinuationKindEnum;
use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;

/**
 * Admits a non-blocking tool-execution suspension into canonical pending human input.
 *
 * Validates active turn/step and unresolved call identity, moves the call from
 * batch inFlight into awaitingHumanInput, appends the request FIFO, sets
 * WaitingHuman, and emits the existing waiting_human payload for runtime/TUI.
 * Does not answer/resume/deny the request.
 */
final readonly class ToolExecutionSuspensionHandler implements RunMessageHandler
{
    public function __construct(
        private ToolBatchCollector $toolBatchCollector,
        private EventFactory $eventFactory,
    ) {
    }

    public function supports(object $message): bool
    {
        return $message instanceof ToolExecutionSuspension;
    }

    public function handle(object $message, RunState $state): HandlerResult
    {
        if (!$message instanceof ToolExecutionSuspension) {
            throw new \InvalidArgumentException('ToolExecutionSuspensionHandler can only handle ToolExecutionSuspension messages.');
        }

        $runId = $message->runId();

        if ($state->turnNo !== $message->turnNo()
            || (null !== $state->activeStepId && $state->activeStepId !== $message->stepId())
            || \in_array($state->status, [RunStatus::Cancelled, RunStatus::Cancelling, RunStatus::Completed, RunStatus::Failed], true)
        ) {
            $nextState = $this->eventFactory->incrementStateVersion($state, eventCount: 1);
            $event = $this->eventFactory->event(
                runId: $runId,
                seq: $nextState->lastSeq,
                turnNo: $state->turnNo,
                type: RunEventTypeEnum::StaleResultIgnored->value,
                payload: [
                    'result' => 'tool_execution_suspension',
                    'tool_call_id' => $message->toolCallId,
                    'step_id' => $message->stepId(),
                    'turn_no' => $message->turnNo(),
                    'status' => $state->status->value,
                ],
            );

            return new HandlerResult(
                nextState: $nextState,
                events: [$event],
            );
        }

        $this->assertRequestShape($message);

        $existing = $this->findExistingRequest($state, $message);
        if (null !== $existing) {
            if ($this->requestsEquivalent($existing, $message->request)) {
                // Idempotent redelivery of the same suspension identity.
                return new HandlerResult(nextState: null, events: []);
            }

            throw new \LogicException(\sprintf('Conflicting tool-execution suspension for call "%s": existing request "%s", new request "%s".', $message->toolCallId, $existing->questionId, $message->request->questionId));
        }

        if (!\array_key_exists($message->toolCallId, $state->pendingToolCalls)
            || true === $state->pendingToolCalls[$message->toolCallId]
        ) {
            throw new \LogicException(\sprintf('Cannot admit tool-execution suspension for resolved or unknown tool call "%s".', $message->toolCallId));
        }

        $effects = $this->toolBatchCollector->admitHumanInputSuspension(
            runId: $runId,
            turnNo: $message->turnNo(),
            stepId: $message->stepId(),
            toolCallId: $message->toolCallId,
            questionId: $message->request->questionId,
        );

        $pendingHumanInputRequests = [...$state->pendingHumanInputRequests, $message->request];
        $eventSpecs = [
            [
                'type' => RunEventTypeEnum::WaitingHuman->value,
                'payload' => $message->request->payload,
            ],
        ];
        $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $eventSpecs);

        $nextState = new RunState(
            runId: $state->runId,
            status: RunStatus::WaitingHuman,
            version: $state->version + 1,
            turnNo: $state->turnNo,
            lastSeq: $state->lastSeq + \count($events),
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: $state->pendingToolCalls,
            errorMessage: $state->errorMessage,
            messages: $state->messages,
            activeStepId: $state->activeStepId,
            retryableFailure: false,
            pendingHumanInputRequests: $pendingHumanInputRequests,
        );

        return new HandlerResult(
            nextState: $nextState,
            events: $events,
            postCommitEffects: $effects,
        );
    }

    private function assertRequestShape(ToolExecutionSuspension $message): void
    {
        $request = $message->request;
        if (HumanInputContinuationKindEnum::ToolCall !== $request->continuationKind) {
            throw new \LogicException('Tool-execution suspension requires ToolCall continuation kind.');
        }

        $ref = $request->continuationRef ?? [];
        $refToolCallId = $ref['tool_call_id'] ?? null;
        if (\is_string($refToolCallId) && '' !== $refToolCallId && $refToolCallId !== $message->toolCallId) {
            throw new \LogicException(\sprintf('Tool-execution suspension continuation_ref.tool_call_id "%s" does not match message toolCallId "%s".', $refToolCallId, $message->toolCallId));
        }

        $payloadQuestionId = $request->payload['question_id'] ?? null;
        if ($request->questionId !== $payloadQuestionId) {
            throw new \LogicException('Tool-execution suspension payload.question_id must match request.questionId.');
        }
    }

    private function findExistingRequest(RunState $state, ToolExecutionSuspension $message): ?PendingHumanInputRequestDTO
    {
        foreach ($state->pendingHumanInputRequests as $request) {
            if (HumanInputContinuationKindEnum::ToolCall !== $request->continuationKind) {
                continue;
            }

            $refToolCallId = $request->continuationRef['tool_call_id'] ?? null;
            if ($refToolCallId === $message->toolCallId || $request->questionId === $message->request->questionId) {
                return $request;
            }
        }

        return null;
    }

    private function requestsEquivalent(PendingHumanInputRequestDTO $left, PendingHumanInputRequestDTO $right): bool
    {
        return $left->questionId === $right->questionId
            && $left->continuationKind === $right->continuationKind
            && $left->payload === $right->payload
            && $left->continuationRef === $right->continuationRef;
    }
}
