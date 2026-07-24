<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

use Ineersa\AgentCore\Contract\Compaction\CompactionPrepareResult;
use Ineersa\AgentCore\Contract\Compaction\CompactionServiceInterface;
use Ineersa\AgentCore\Contract\Compaction\CompactResult;
use Ineersa\AgentCore\Contract\Compaction\MessageSnapshotCompactionResult;
use Ineersa\AgentCore\Contract\Model\PlatformInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationOptions;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * CodingAgent adapter that implements the AgentCore compaction contract.
 *
 * Wraps {@see SessionCompactor} and {@see CompactionConfig} to bridge
 * the CodingAgent compaction domain into the AgentCore pipeline without
 * leaking internal DTOs or namespaces into AgentCore.
 */
final readonly class CompactionService implements CompactionServiceInterface
{
    public function __construct(
        private SessionCompactor $sessionCompactor,
        private AppConfig $appConfig,
        private ModelSelectionService $modelSelectionService,
        private CompactionHookDispatcher $hookDispatcher,
        private PlatformInterface $platform,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function prepare(array $messages): CompactionPrepareResult
    {
        $result = $this->sessionCompactor->prepare($messages, $this->appConfig->compaction);

        if (!$result->isReady()) {
            // Map CodingAgent CompactionSkipReasonEnum to a string reason
            // that AgentCore can use without importing the enum.
            $reason = $result->skipReason->value;

            return CompactionPrepareResult::failed($reason);
        }

        $prep = $result->preparation;

        return CompactionPrepareResult::ready(
            messagesToSummarize: $prep->messagesToSummarize,
            retainedTailMessages: $prep->retainedTailMessages,
            tokenEstimateBefore: $prep->tokenEstimateBefore,
            messagesCompacted: $prep->messagesCompacted,
            messagesRetained: $prep->messagesRetained,
            firstRetainedIndex: $prep->firstRetainedIndex,
            priorSummaryPresent: $prep->priorSummaryPresent,
        );
    }

    public function buildSummarizationMessages(
        CompactionPrepareResult $result,
        ?string $customInstructions,
    ): array {
        // Map the AgentCore contract DTO back to the CodingAgent
        // CompactionPreparationDTO that SessionCompactor expects.
        $preparation = new CompactionPreparationDTO(
            messagesToSummarize: $result->messagesToSummarize ?? [],
            retainedTailMessages: $result->retainedTailMessages ?? [],
            tokenEstimateBefore: $result->tokenEstimateBefore,
            messagesCompacted: $result->messagesCompacted,
            messagesRetained: $result->messagesRetained,
            firstRetainedIndex: $result->firstRetainedIndex,
            priorSummaryPresent: $result->priorSummaryPresent,
        );

        return $this->sessionCompactor->buildSummarizationMessages(
            $preparation,
            $customInstructions,
        );
    }

    public function buildCompactedMessages(
        string $summaryText,
        CompactionPrepareResult $result,
    ): CompactResult {
        $preparation = new CompactionPreparationDTO(
            messagesToSummarize: $result->messagesToSummarize ?? [],
            retainedTailMessages: $result->retainedTailMessages ?? [],
            tokenEstimateBefore: $result->tokenEstimateBefore,
            messagesCompacted: $result->messagesCompacted,
            messagesRetained: $result->messagesRetained,
            firstRetainedIndex: $result->firstRetainedIndex,
            priorSummaryPresent: $result->priorSummaryPresent,
        );

        $compacted = $this->sessionCompactor->buildCompactedMessages($summaryText, $preparation);

        return new CompactResult(
            summaryText: $compacted->summaryText,
            summaryMessage: $compacted->summaryMessage,
            compactedMessages: $compacted->compactedMessages,
            tokenEstimateBefore: $compacted->tokenEstimateBefore,
            tokenEstimateAfter: $compacted->tokenEstimateAfter,
            messagesCompacted: $compacted->messagesCompacted,
            messagesRetained: $compacted->messagesRetained,
            firstRetainedIndex: $compacted->firstRetainedIndex,
        );
    }

    public function compactMessages(
        string $runId,
        int $turnNo,
        array $messages,
        string $trigger = 'manual',
        ?string $customInstructions = null,
        ?string $activeModel = null,
    ): MessageSnapshotCompactionResult {
        RunLogContext::enter([
            'run_id' => $runId,
            'session_id' => $runId,
            'component' => 'compaction',
            'event_type' => 'compaction.snapshot.started',
            'trigger' => $trigger,
        ]);

        try {
            return $this->doCompactMessages($runId, $turnNo, $messages, $trigger, $customInstructions, $activeModel);
        } finally {
            RunLogContext::leave();
        }
    }

    /**
     * @param list<AgentMessage> $messages
     */
    private function doCompactMessages(
        string $runId,
        int $turnNo,
        array $messages,
        string $trigger,
        ?string $customInstructions,
        ?string $activeModel = null,
    ): MessageSnapshotCompactionResult {
        // Prefer canonical RunState model snapshot from the caller; only fall
        // back to session/default for legacy snapshot entry points that cannot
        // supply execution identity yet.
        $activeModelStr = null !== $activeModel && '' !== trim($activeModel)
            ? trim($activeModel)
            : $this->modelSelectionService->getCurrentModel($runId)?->toString();
        $runtimeSettings = $this->appConfig->compaction->resolveRuntimeSettings($activeModelStr);
        $thinkingLevel = $runtimeSettings->thinkingLevel;
        $modelOptions = null !== $thinkingLevel && '' !== $thinkingLevel
            ? ['thinking_level' => $thinkingLevel]
            : [];

        $this->logger->info('Compaction snapshot preparation started.', [
            'event_type' => 'compaction.snapshot.prepare.started',
            'run_id' => $runId,
            'turn_no' => $turnNo,
            'messages_total' => \count($messages),
            'trigger' => $trigger,
        ]);

        $preparation = $this->prepare($messages);
        if (!$preparation->isReady()) {
            $skipReason = $preparation->failureReason ?? 'unknown';
            $this->logger->info('Compaction snapshot structural no-op.', [
                'event_type' => 'compaction.snapshot.structural_noop',
                'run_id' => $runId,
                'reason' => $skipReason,
                'trigger' => $trigger,
            ]);

            return MessageSnapshotCompactionResult::structuralNoOp($messages, $skipReason);
        }

        $resolvedModel = $runtimeSettings->model ?? $activeModelStr;
        $hookContext = new CompactionHookContextDTO(
            runId: $runId,
            turnNo: $turnNo,
            trigger: $trigger,
            tokenEstimateBefore: $preparation->tokenEstimateBefore,
            messagesCompacted: $preparation->messagesCompacted,
            messagesRetained: $preparation->messagesRetained,
            firstRetainedIndex: $preparation->firstRetainedIndex,
            priorSummaryPresent: $preparation->priorSummaryPresent,
            customInstructions: $customInstructions,
            resolvedModel: $resolvedModel,
            thinkingLevel: $thinkingLevel,
        );
        $hookResult = $this->hookDispatcher->dispatch($hookContext);

        if ($hookResult->cancels()) {
            $reason = 'hook_cancelled: '.$hookResult->cancelReason;
            $this->logger->info('Compaction snapshot cancelled by before-compaction hook.', [
                'event_type' => 'compaction.snapshot.hook.cancelled',
                'run_id' => $runId,
                'hook_reason' => $hookResult->cancelReason,
            ]);

            return MessageSnapshotCompactionResult::failed(
                $reason,
                'Compaction cancelled: '.$hookResult->cancelReason,
            );
        }

        $effectiveInstructions = $customInstructions;
        if ($hookResult->hasAdditionalInstructions()) {
            $effectiveInstructions = null !== $effectiveInstructions
                ? $effectiveInstructions."\n".$hookResult->additionalInstructions
                : $hookResult->additionalInstructions;
        }

        if ($hookResult->hasReplacementSummary()) {
            // Match CompactRunHandler::handleReplacementSummary: hook-provided
            // replacement summaries skip the ordinary ineffective-compaction rejection.
            return $this->finalizeFromSummary(
                $hookResult->replacementSummary,
                $preparation,
                $runId,
                $trigger,
                rejectIneffective: false,
                originalMessages: $messages,
            );
        }

        $summarizationMessages = $this->buildSummarizationMessages($preparation, $effectiveInstructions);
        $stepId = \sprintf('snapshot-compact-%s', bin2hex(random_bytes(8)));
        $model = $resolvedModel ?? '';

        try {
            $response = $this->platform->invoke(new ModelInvocationRequest(
                model: $model,
                input: new ModelInvocationInput(
                    runId: $runId,
                    turnNo: $turnNo,
                    stepId: $stepId,
                    messages: $summarizationMessages,
                ),
                options: new ModelInvocationOptions(
                    toolsEnabled: false,
                    extraOptions: $modelOptions,
                    streamObserverEnabled: false,
                ),
            ));
        } catch (\Throwable $exception) {
            $rawMessage = $exception->getMessage();
            $cappedMessage = mb_substr($rawMessage, 0, 200);
            $this->logger->error('Compaction snapshot model invocation failed.', [
                'event_type' => 'compaction.snapshot.failed',
                'run_id' => $runId,
                'error_type' => $exception::class,
            ]);

            return MessageSnapshotCompactionResult::failed(
                'model_error',
                \sprintf(
                    'Compaction failed: The summarization model call could not be completed. %s',
                    '' !== $cappedMessage ? '(Detail: '.$cappedMessage.')' : '',
                ),
            );
        }

        if (null !== $response->error) {
            $error = $response->error;
            $userMessage = \is_string($error['user_message'] ?? null) && '' !== $error['user_message']
                ? $error['user_message']
                : (\is_string($error['message'] ?? null) && '' !== $error['message']
                    ? $error['message']
                    : 'Summarization model call failed.');

            $this->logger->error('Compaction snapshot model invocation failed.', [
                'event_type' => 'compaction.snapshot.failed',
                'run_id' => $runId,
                'error_type' => $error['type'] ?? 'unknown',
            ]);

            return MessageSnapshotCompactionResult::failed('model_error', $userMessage);
        }

        $summaryText = null !== $response->assistantMessage
            ? trim((string) $response->assistantMessage->asText())
            : '';
        if ('' === $summaryText) {
            $this->logger->info('Compaction snapshot produced empty summary.', [
                'event_type' => 'compaction.snapshot.empty_summary',
                'run_id' => $runId,
            ]);

            return MessageSnapshotCompactionResult::failed(
                'empty_summary',
                'Compaction failed: summarization model returned an empty summary.',
            );
        }

        return $this->finalizeFromSummary($summaryText, $preparation, $runId, $trigger, rejectIneffective: true, originalMessages: $messages);
    }

    /**
     * @param list<AgentMessage> $originalMessages
     */
    private function finalizeFromSummary(
        string $summaryText,
        CompactionPrepareResult $preparation,
        string $runId,
        string $trigger,
        bool $rejectIneffective,
        array $originalMessages,
    ): MessageSnapshotCompactionResult {
        $compactResult = $this->buildCompactedMessages($summaryText, $preparation);

        if ($rejectIneffective && $compactResult->tokenEstimateAfter >= $compactResult->tokenEstimateBefore) {
            $this->logger->info('Compaction snapshot was ineffective.', [
                'event_type' => 'compaction.snapshot.ineffective',
                'run_id' => $runId,
                'estimated_tokens_before' => $compactResult->tokenEstimateBefore,
                'estimated_tokens_after' => $compactResult->tokenEstimateAfter,
                'trigger' => $trigger,
            ]);

            // Snapshot API only: an ineffective summary must not hard-fail callers.
            // Reject the larger summary and continue with the exact original messages
            // (same semantics as prepare structural skips). Async /compact still emits
            // context_compaction_failed via CompactionStepResultHandler.
            return MessageSnapshotCompactionResult::structuralNoOp(
                $originalMessages,
                'ineffective_compaction',
            );
        }

        $this->logger->info('Compaction snapshot applied.', [
            'event_type' => 'compaction.snapshot.applied',
            'run_id' => $runId,
            'messages_compacted' => $compactResult->messagesCompacted,
            'messages_retained' => $compactResult->messagesRetained,
            'trigger' => $trigger,
            'replacement_summary' => !$rejectIneffective,
        ]);

        return MessageSnapshotCompactionResult::compacted($compactResult->compactedMessages);
    }
}
