<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Pipeline\RunMessageHandler;
use Ineersa\AgentCore\Contract\Compaction\CompactionServiceInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Domain\Message\ExecuteCompactionStep;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ModelSelectionService;

/**
 * Handles {@see CompactRun} messages: prepares compaction partitions,
 * emits started/failed events, and dispatches an async
 * {@see ExecuteCompactionStep} for model invocation when ready.
 *
 * Lives in CodingAgent because it depends on CompactionServiceInterface
 * which is implemented by the CodingAgent compaction layer. The handler
 * is registered as a tagged RunMessageHandler service and discovered
 * by RunMessageProcessor at runtime.
 */
final readonly class CompactRunHandler implements RunMessageHandler
{
    public function __construct(
        private CompactionServiceInterface $compactionService,
        private AppConfig $appConfig,
        private EventFactory $eventFactory,
        private ModelSelectionService $modelSelectionService,
    ) {
    }

    public function supports(object $message): bool
    {
        return $message instanceof CompactRun;
    }

    public function handle(object $message, RunState $state): HandlerResult
    {
        if (!$message instanceof CompactRun) {
            throw new \InvalidArgumentException('CompactRunHandler can only handle CompactRun messages.');
        }

        $runId = $message->runId();

        // Resolve the active session model for compaction override resolution.
        // session_id === run_id per AGENTS.md; ModelSelectionService reads it
        // from session metadata so per-model/per-provider compaction overrides
        // apply based on the currently active chat model.
        $activeModel = $this->modelSelectionService->getCurrentModel($runId);
        $activeModelStr = $activeModel?->toString();

        $runtimeSettings = $this->appConfig->compaction->resolveRuntimeSettings($activeModelStr);
        $resolvedModel = $runtimeSettings->model;
        $thinkingLevel = $runtimeSettings->thinkingLevel;

        $preparation = $this->compactionService->prepare($state->messages);

        if (!$preparation->isReady()) {
            // Structural failure — emit context_compaction_failed and
            // preserve messages. No model call was started.
            $failureReason = $preparation->failureReason ?? 'unknown';
            $userMessage = $this->failureReasonToMessage($failureReason);

            $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, [[
                'type' => RunEventTypeEnum::ContextCompactionFailed->value,
                'payload' => [
                    'reason' => $failureReason,
                    'message' => $userMessage,
                    'preserved_messages' => true,
                    'trigger' => $message->trigger,
                ],
            ]]);

            return new HandlerResult(
                nextState: $this->incrementState($state, $events),
                events: $events,
            );
        }

        // Build summarization messages for the model call.
        $summarizationMessages = $this->compactionService->buildSummarizationMessages(
            $preparation,
            $message->customInstructions,
        );

        // Emit context_compaction_started.
        $startedEvents = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, [[
            'type' => RunEventTypeEnum::ContextCompactionStarted->value,
            'payload' => [
                'trigger' => $message->trigger,
                'model' => $resolvedModel,
                'thinking_level' => $thinkingLevel,
                'estimated_tokens' => $preparation->tokenEstimateBefore,
                'keep_recent_tokens' => $runtimeSettings->keepRecentTokens,
                'messages_before' => \count($state->messages),
                'messages_to_summarize' => $preparation->messagesCompacted,
                'messages_retained' => $preparation->messagesRetained,
                'first_retained_index' => $preparation->firstRetainedIndex,
                'prior_summary_present' => $preparation->priorSummaryPresent,
            ],
        ]]);

        $nextState = $this->incrementState($state, $startedEvents, activeStepId: $message->stepId());

        // Dispatch async worker for model invocation.
        // Serialize AgentMessage lists as array shapes for transport safety
        // (the llm transport uses default Symfony Serializer, not PhpSerializer).
        $serializedSummarization = array_map(
            static fn (AgentMessage $msg): array => $msg->toArray(),
            $summarizationMessages,
        );
        $serializedRetained = array_map(
            static fn (AgentMessage $msg): array => $msg->toArray(),
            $preparation->retainedTailMessages ?? [],
        );

        $workerRequest = new ExecuteCompactionStep(
            runId: $runId,
            turnNo: $state->turnNo,
            stepId: $message->stepId(),
            attempt: 1,
            idempotencyKey: hash('sha256', \sprintf('%s|compaction|%d|%s', $runId, $state->turnNo, $message->stepId())),
            model: $resolvedModel ?? '', // Empty string => adapter resolves from defaults
            thinkingLevel: $thinkingLevel,
            summarizationMessages: $serializedSummarization,
            retainedTailMessages: $serializedRetained,
            messagesCompacted: $preparation->messagesCompacted,
            messagesRetained: $preparation->messagesRetained,
            firstRetainedIndex: $preparation->firstRetainedIndex,
            tokenEstimateBefore: $preparation->tokenEstimateBefore,
            trigger: $message->trigger,
        );

        // Idempotency: when messages were already applied during this
        // checkpoint, the result handler replays; don't re-apply.
        // But if the handler was not reached yet (messages added by steer/
        // follow-up after compaction started), they will be present in the
        // new state after the handler processes. The idempotency guards
        // in RunMessageProcessor prevent double-processing.

        return new HandlerResult(
            nextState: $nextState,
            events: $startedEvents,
            effects: [$workerRequest],
        );
    }

    /**
     * @param list<\Ineersa\AgentCore\Domain\Event\RunEvent> $events
     */
    /**
     * @param list<\Ineersa\AgentCore\Domain\Event\RunEvent> $events
     */
    private function incrementState(RunState $state, array $events, ?string $activeStepId = null): RunState
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
            // null = preserve current; non-null = override. The started
            // path passes the compaction stepId explicitly; no other path
            // overrides, so failure paths keep activeStepId unchanged.
            activeStepId: $activeStepId ?? $state->activeStepId,
            retryableFailure: $state->retryableFailure,
        );
    }

    /**
     * Map a structural skip reason to a human-readable failure message.
     *
     * These are NOT skips — when prepare returns not-ready, we emit
     * context_compaction_failed. The wording uses "Compaction failed"
     * or "Compaction not possible", never "skipped".
     *
     * Reasons come from CompactionSkipReasonEnum via CompactionServiceInterface,
     * but the handler lives in CodingAgent so the mapping is local.
     */
    private function failureReasonToMessage(string $reason): string
    {
        return match ($reason) {
            'too_few_messages' => 'Compaction failed: there are not enough messages to compact.',
            'below_keep_recent_tokens' => 'Compaction failed: there is no older context outside the retained tail to summarize.',
            'no_boundary' => 'Compaction failed: could not determine a boundary for the retained tail.',
            'no_safe_boundary' => 'Compaction failed: no safe boundary found without splitting tool-call results.',
            default => 'Compaction failed: '.$reason,
        };
    }
}
