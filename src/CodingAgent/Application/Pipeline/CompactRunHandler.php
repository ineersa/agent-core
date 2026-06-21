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
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Domain\Message\ExecuteCompactionStep;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Ineersa\CodingAgent\Compaction\CompactionHookContextDTO;
use Ineersa\CodingAgent\Compaction\CompactionHookDispatcher;
use Ineersa\CodingAgent\Compaction\CompactionPreparationDTO;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handles {@see CompactRun} messages: prepares compaction partitions,
 * invokes before-compaction hooks, emits started/failed events, and
 * dispatches an async {@see ExecuteCompactionStep} for model invocation
 * when ready.
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
        private CompactionHookDispatcher $hookDispatcher,
        private LoggerInterface $logger = new NullLogger(),
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
        $resolvedModel = $runtimeSettings->model ?? $activeModelStr;
        $thinkingLevel = $runtimeSettings->thinkingLevel;

        // Build opaque model options bag for the async worker.
        $modelOptions = null !== $thinkingLevel && '' !== $thinkingLevel
            ? ['thinking_level' => $thinkingLevel]
            : [];

        RunLogContext::enter([
            'run_id' => $runId,
            'session_id' => $runId,
            'component' => 'compaction',
        ]);

        try {
            return $this->handleCompaction($message, $state, $runId, $activeModelStr, $runtimeSettings, $thinkingLevel, $modelOptions);
        } finally {
            RunLogContext::leave();
        }
    }

    /**
     * Core compaction logic after model resolution and context setup.
     *
     * Separated from handle() so RunLogContext scoping wraps only the
     * compaction path, not the model resolution preamble.
     *
     * @param array<string, mixed> $modelOptions Opaque model options bag for the async worker
     */
    private function handleCompaction(
        CompactRun $message,
        RunState $state,
        string $runId,
        ?string $activeModelStr,
        object $runtimeSettings,
        ?string $thinkingLevel,
        array $modelOptions,
    ): HandlerResult {
        $this->logger->info('Compaction preparation started.', [
            'event_type' => 'compaction.prepare.started',
            'run_id' => $runId,
            'turn_no' => $state->turnNo,
            'messages_total' => \count($state->messages),
            'trigger' => $message->trigger,
        ]);

        $preparation = $this->compactionService->prepare($state->messages);

        if (!$preparation->isReady()) {
            $failureReason = $preparation->failureReason ?? 'unknown';
            $userMessage = $this->failureReasonToMessage($failureReason);

            $this->logger->info('Compaction preparation failed.', [
                'event_type' => 'compaction.prepare.failed',
                'run_id' => $runId,
                'reason' => $failureReason,
            ]);

            $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, [[
                'type' => RunEventTypeEnum::ContextCompactionFailed->value,
                'payload' => [
                    'reason' => $failureReason,
                    'message' => $userMessage,
                    'messages_replaced' => false,
                    'trigger' => $message->trigger,
                ],
            ]]);

            return new HandlerResult(
                nextState: $this->incrementState($state, $events),
                events: $events,
            );
        }

        $resolvedModel = $runtimeSettings->model ?? $activeModelStr;

        // ── Before-compaction hooks ──
        $hookContext = new CompactionHookContextDTO(
            runId: $runId,
            turnNo: $state->turnNo,
            trigger: $message->trigger,
            preparation: new CompactionPreparationDTO(
                messagesToSummarize: $preparation->messagesToSummarize ?? [],
                retainedTailMessages: $preparation->retainedTailMessages ?? [],
                tokenEstimateBefore: $preparation->tokenEstimateBefore,
                messagesCompacted: $preparation->messagesCompacted,
                messagesRetained: $preparation->messagesRetained,
                firstRetainedIndex: $preparation->firstRetainedIndex,
                priorSummaryPresent: $preparation->priorSummaryPresent,
            ),
            customInstructions: $message->customInstructions,
            resolvedModel: $resolvedModel,
            thinkingLevel: $thinkingLevel,
        );

        $hookResult = $this->hookDispatcher->dispatch($hookContext);

        // Hook cancel: emit context_compaction_failed, no worker dispatch.
        if ($hookResult->cancels()) {
            $cancelReason = 'hook_cancelled: '.$hookResult->cancelReason;

            $this->logger->info('Compaction cancelled by before-compaction hook.', [
                'event_type' => 'compaction.hook.cancelled',
                'run_id' => $runId,
                'hook_reason' => $hookResult->cancelReason,
            ]);

            $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, [[
                'type' => RunEventTypeEnum::ContextCompactionFailed->value,
                'payload' => [
                    'reason' => $cancelReason,
                    'message' => 'Compaction cancelled: '.$hookResult->cancelReason,
                    'messages_replaced' => false,
                    'trigger' => $message->trigger,
                    'hook_metadata' => $hookResult->metadata,
                ],
            ]]);

            return new HandlerResult(
                nextState: $this->incrementState($state, $events),
                events: $events,
            );
        }

        // Effective custom instructions: original + hook additional.
        $effectiveInstructions = $message->customInstructions;
        if ($hookResult->hasAdditionalInstructions()) {
            $effectiveInstructions = null !== $effectiveInstructions
                ? $effectiveInstructions."\n".$hookResult->additionalInstructions
                : $hookResult->additionalInstructions;
        }

        // Replacement summary: skip LLM, build compacted messages directly.
        if ($hookResult->hasReplacementSummary()) {
            return $this->handleReplacementSummary(
                runId: $runId,
                state: $state,
                message: $message,
                preparation: $preparation,
                replacementSummary: $hookResult->replacementSummary,
                thinkingLevel: $thinkingLevel,
                resolvedModel: $resolvedModel,
                hookMetadata: $hookResult->metadata,
            );
        }

        // ── Normal async LLM path ──

        $summarizationMessages = $this->compactionService->buildSummarizationMessages(
            $preparation,
            $effectiveInstructions,
        );

        $this->logger->info('Dispatching compaction worker.', [
            'event_type' => 'compaction.started',
            'run_id' => $runId,
            'turn_no' => $state->turnNo,
            'model' => $resolvedModel,
            'estimated_tokens' => $preparation->tokenEstimateBefore,
            'messages_to_summarize' => $preparation->messagesCompacted,
        ]);

        $startedEvents = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, [[
            'type' => RunEventTypeEnum::ContextCompactionStarted->value,
            'payload' => [
                'step_id' => $message->stepId(),
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
                'hook_metadata' => $hookResult->metadata,
            ],
        ]]);

        $nextState = $this->incrementState($state, $startedEvents, activeStepId: $message->stepId());

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
            model: $resolvedModel ?? '',
            modelOptions: $modelOptions,
            summarizationMessages: $serializedSummarization,
            retainedTailMessages: $serializedRetained,
            messagesCompacted: $preparation->messagesCompacted,
            messagesRetained: $preparation->messagesRetained,
            firstRetainedIndex: $preparation->firstRetainedIndex,
            tokenEstimateBefore: $preparation->tokenEstimateBefore,
            trigger: $message->trigger,
        );

        return new HandlerResult(
            nextState: $nextState,
            events: $startedEvents,
            effects: [$workerRequest],
        );
    }

    /**
     * Handle a hook-provided replacement summary: skip the LLM call,
     * build compacted messages from the replacement text, and emit
     * context_compaction_started → context_compacted with hook metadata.
     *
     * The replacement summary path still emits the mandatory lifecycle
     * events (started + compacted) and rewrites RunState.messages, but
     * never dispatches an ExecuteCompactionStep worker.
     *
     * @param array<string, mixed> $hookMetadata JSON-safe metadata from hooks
     */
    private function handleReplacementSummary(
        string $runId,
        RunState $state,
        CompactRun $message,
        CompactionPrepareResult $preparation,
        string $replacementSummary,
        ?string $thinkingLevel,
        ?string $resolvedModel,
        array $hookMetadata,
    ): HandlerResult {
        $this->logger->info('Using hook-provided replacement summary, skipping LLM call.', [
            'event_type' => 'compaction.hook.replacement_summary',
            'run_id' => $runId,
            'summary_length' => \strlen($replacementSummary),
        ]);

        // Emit context_compaction_started with replacement flag.
        $startedEvents = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, [[
            'type' => RunEventTypeEnum::ContextCompactionStarted->value,
            'payload' => [
                'step_id' => $message->stepId(),
                'trigger' => $message->trigger,
                'model' => $resolvedModel,
                'thinking_level' => $thinkingLevel,
                'estimated_tokens' => $preparation->tokenEstimateBefore,
                'messages_before' => \count($state->messages),
                'messages_to_summarize' => $preparation->messagesCompacted,
                'messages_retained' => $preparation->messagesRetained,
                'first_retained_index' => $preparation->firstRetainedIndex,
                'hook_metadata' => $hookMetadata,
                'replacement_summary' => true,
            ],
        ]]);

        $afterStartState = $this->incrementState($state, $startedEvents, activeStepId: $message->stepId());

        // Build compacted messages from the replacement summary.
        $compactResult = $this->compactionService->buildCompactedMessages(
            $replacementSummary,
            $preparation,
        );

        $serializedMessages = array_map(
            static fn (AgentMessage $msg): array => $msg->toArray(),
            $compactResult->compactedMessages,
        );

        $compactedEvents = $this->eventFactory->eventsFromSpecs(
            $runId,
            $state->turnNo,
            $afterStartState->lastSeq + 1,
            [[
                'type' => RunEventTypeEnum::ContextCompacted->value,
                'payload' => [
                    'summary_text' => $replacementSummary,
                    'messages' => $serializedMessages,
                    'estimated_tokens_before' => $compactResult->tokenEstimateBefore,
                    'estimated_tokens_after' => $compactResult->tokenEstimateAfter,
                    'messages_compacted' => $compactResult->messagesCompacted,
                    'messages_retained' => $compactResult->messagesRetained,
                    'first_retained_index' => $compactResult->firstRetainedIndex,
                    'model' => $resolvedModel,
                    'thinking_level' => $thinkingLevel,
                    'trigger' => $message->trigger,
                    'hook_metadata' => $hookMetadata,
                    'replacement_summary' => true,
                ],
            ]],
        );

        // Replace RunState.messages with compacted messages.
        $nextState = new RunState(
            runId: $afterStartState->runId,
            status: $afterStartState->status,
            version: $afterStartState->version + 1,
            turnNo: $afterStartState->turnNo,
            lastSeq: $afterStartState->lastSeq + \count($compactedEvents),
            isStreaming: $afterStartState->isStreaming,
            streamingMessage: $afterStartState->streamingMessage,
            pendingToolCalls: $afterStartState->pendingToolCalls,
            errorMessage: $afterStartState->errorMessage,
            messages: $compactResult->compactedMessages,
            activeStepId: null,
            retryableFailure: $afterStartState->retryableFailure,
        );

        return new HandlerResult(
            nextState: $nextState,
            events: array_merge($startedEvents, $compactedEvents),
        );
    }

    /**
     * @param list<\Ineersa\AgentCore\Domain\Event\RunEvent> $events
     * @param string|null                                    $activeStepId null = preserve current; non-null = override
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
            activeStepId: $activeStepId ?? $state->activeStepId,
            retryableFailure: $state->retryableFailure,
        );
    }

    /**
     * Map a structural skip reason to a human-readable failure message.
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
