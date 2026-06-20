<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Pipeline\RunMessageHandler;
use Ineersa\AgentCore\Contract\Compaction\CompactionPrepareResult;
use Ineersa\AgentCore\Contract\Compaction\CompactionServiceInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\CompactionStepResult;
use Ineersa\AgentCore\Domain\Run\RunState;

/**
 * Handles {@see CompactionStepResult} messages from async compaction workers.
 *
 * Validates staleness, processes the summary text through the compaction
 * service, and emits either context_compacted (replacing messages) or
 * context_compaction_failed (preserving messages).
 *
 * Lives in CodingAgent because it depends on CompactionServiceInterface
 * for building the compacted message list.
 */
final readonly class CompactionStepResultHandler implements RunMessageHandler
{
    public function __construct(
        private CompactionServiceInterface $compactionService,
        private EventFactory $eventFactory,
    ) {
    }

    public function supports(object $message): bool
    {
        return $message instanceof CompactionStepResult;
    }

    public function handle(object $message, RunState $state): HandlerResult
    {
        if (!$message instanceof CompactionStepResult) {
            throw new \InvalidArgumentException('CompactionStepResultHandler can only handle CompactionStepResult messages.');
        }

        $runId = $message->runId();

        // Guard: if the turn number no longer matches or the stepId no longer
        // matches the active step, the result is stale.  Emit a terminal
        // context_compaction_failed event so the user-visible compaction
        // state is resolved instead of leaving a dangling started event.
        //
        // Also guard against terminal run states (Completed, Failed, Cancelled)
        // where the result arrived after the run already finished.
        if ($state->turnNo !== $message->turnNo() || $state->activeStepId !== $message->stepId()) {
            $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, [[
                'type' => RunEventTypeEnum::ContextCompactionFailed->value,
                'payload' => [
                    'reason' => 'stale_result',
                    'message' => 'Compaction result arrived too late — the active step has moved on.',
                    'preserved_messages' => true,
                    'trigger' => $message->trigger,
                ],
            ]]);

            return new HandlerResult(
                nextState: $this->incrementState($state, $events),
                events: $events,
            );
        }

        // If the run is in a terminal state (Completed, Failed, Cancelled),
        // the compaction result arrived too late.
        if (\in_array($state->status->value, ['completed', 'failed', 'cancelled'], true)) {
            $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, [[
                'type' => RunEventTypeEnum::ContextCompactionFailed->value,
                'payload' => [
                    'reason' => 'stale_result',
                    'message' => 'Compaction result arrived after the run ended.',
                    'preserved_messages' => true,
                    'trigger' => $message->trigger,
                ],
            ]]);

            return new HandlerResult(
                nextState: $this->incrementState($state, $events),
                events: $events,
            );
        }

        // Error from model invocation → emit failure, preserve messages.
        if (null !== $message->error) {
            $reason = 'model_error';
            $errorMessage = \is_string($message->error['message'] ?? null)
                ? $message->error['message']
                : 'Summarization model call failed.';

            $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, [[
                'type' => RunEventTypeEnum::ContextCompactionFailed->value,
                'payload' => [
                    'reason' => $reason,
                    'message' => $errorMessage,
                    'preserved_messages' => true,
                    'model' => $message->model,
                    'thinking_level' => $message->thinkingLevel,
                    'trigger' => $message->trigger,
                ],
            ]]);

            return new HandlerResult(
                nextState: $this->incrementState($state, $events, clearActiveStepId: true),
                events: $events,
            );
        }

        // Empty or whitespace-only summary → emit failure, preserve messages.
        $summaryText = \is_string($message->summaryText) ? trim($message->summaryText) : '';

        if ('' === $summaryText) {
            $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, [[
                'type' => RunEventTypeEnum::ContextCompactionFailed->value,
                'payload' => [
                    'reason' => 'empty_summary',
                    'message' => 'Compaction failed: summarization model returned an empty summary.',
                    'preserved_messages' => true,
                    'model' => $message->model,
                    'thinking_level' => $message->thinkingLevel,
                    'trigger' => $message->trigger,
                ],
            ]]);

            return new HandlerResult(
                nextState: $this->incrementState($state, $events, clearActiveStepId: true),
                events: $events,
            );
        }

        // Success: build compacted messages and replace RunState.messages.
        // Deserialize retained tail messages from transport-safe array shapes.
        $retainedTail = [];
        foreach ($message->retainedTailMessages as $raw) {
            if (!\is_array($raw)) {
                continue;
            }
            $msg = AgentMessage::fromPayload($raw);
            if (null !== $msg) {
                $retainedTail[] = $msg;
            }
        }

        $preparation = CompactionPrepareResult::ready(
            messagesToSummarize: [], // Not needed for buildCompactedMessages
            retainedTailMessages: $retainedTail,
            tokenEstimateBefore: $message->tokenEstimateBefore,
            messagesCompacted: $message->messagesCompacted,
            messagesRetained: $message->messagesRetained,
            firstRetainedIndex: $message->firstRetainedIndex,
            priorSummaryPresent: false,
        );

        $compactResult = $this->compactionService->buildCompactedMessages(
            $summaryText,
            $preparation,
        );

        // Serialize compacted messages to array representation for the
        // context_compacted payload. The replay path reconstructs
        // AgentMessage instances from toArray() output via AgentMessage::fromPayload().
        $serializedMessages = array_map(
            static fn (AgentMessage $msg): array => $msg->toArray(),
            $compactResult->compactedMessages,
        );

        $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, [[
            'type' => RunEventTypeEnum::ContextCompacted->value,
            'payload' => [
                'summary_text' => $summaryText,
                'messages' => $serializedMessages,
                'estimated_tokens_before' => $compactResult->tokenEstimateBefore,
                'estimated_tokens_after' => $compactResult->tokenEstimateAfter,
                'messages_compacted' => $compactResult->messagesCompacted,
                'messages_retained' => $compactResult->messagesRetained,
                'first_retained_index' => $compactResult->firstRetainedIndex,
                'model' => $message->model,
                'thinking_level' => $message->thinkingLevel,
                'trigger' => $message->trigger,
            ],
        ]]);

        // Atomically replace RunState.messages with the compacted list.
        $nextState = new RunState(
            runId: $state->runId,
            status: $state->status,
            version: $state->version + 1,
            turnNo: $state->turnNo,
            lastSeq: $state->lastSeq + \count($events),
            isStreaming: $state->isStreaming,
            streamingMessage: $state->streamingMessage,
            pendingToolCalls: $state->pendingToolCalls,
            errorMessage: $state->errorMessage,
            messages: $compactResult->compactedMessages,
            activeStepId: null,
            retryableFailure: $state->retryableFailure,
        );

        return new HandlerResult(
            nextState: $nextState,
            events: $events,
        );
    }

    /**
     * @param list<\Ineersa\AgentCore\Domain\Event\RunEvent> $events
     * @param bool                                           $clearActiveStepId when true, set activeStepId to null (terminal outcome)
     */
    private function incrementState(RunState $state, array $events, bool $clearActiveStepId = false): RunState
    {
        $count = \count($events);

        return new RunState(
            runId: $state->runId,
            status: $state->status,
            version: $state->version + 1,
            turnNo: $state->turnNo,
            lastSeq: $state->lastSeq + $count,
            isStreaming: $state->isStreaming,
            streamingMessage: $state->streamingMessage,
            pendingToolCalls: $state->pendingToolCalls,
            errorMessage: $state->errorMessage,
            messages: $state->messages,
            activeStepId: $clearActiveStepId ? null : $state->activeStepId,
            retryableFailure: $state->retryableFailure,
        );
    }
}
