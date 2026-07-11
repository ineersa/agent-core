<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

use Ineersa\AgentCore\Contract\Compaction\CompactionPrepareResult;
use Ineersa\AgentCore\Contract\Compaction\CompactionServiceInterface;
use Ineersa\AgentCore\Contract\Model\PlatformInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationOptions;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\CodingAgent\Agent\Fork\ForkCompactionFailureReasonEnum;
use Ineersa\CodingAgent\Agent\Fork\ForkCompactionSummarizationException;
use Ineersa\CodingAgent\Config\CompactionConfig;
use Ineersa\CodingAgent\Compaction\CompactionBoundarySelector;
use Ineersa\CodingAgent\Compaction\CompactionTokenEstimator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Synchronous virtual compaction for fork snapshots.
 *
 * Reuses the same model resolution, settings overrides, prompt building,
 * summarization invoke, ineffective-summary guard, and compacted message
 * assembly as /compact — without dispatching CompactRun, mutating parent
 * RunState, or appending parent compaction events.
 */
final readonly class VirtualCompactionOrchestrator implements VirtualCompactionOrchestratorInterface
{
    public function __construct(
        private CompactionServiceInterface $compactionService,
        private SessionCompactor $sessionCompactor,
        private CompactionConfig $compactionConfig,
        private ActiveModelResolverInterface $activeModelResolver,
        private PlatformInterface $platform,
        private CompactionBoundarySelector $boundarySelector,
        private CompactionTokenEstimator $tokenEstimator,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param list<AgentMessage> $messages Sanitized parent snapshot (not mutated)
     */
    public function compactForRun(string $runId, array $messages, bool $force = false): VirtualCompactionResult
    {
        if ([] === $messages) {
            return new VirtualCompactionResult(compactedMessages: [], compacted: false);
        }

        $activeModelStr = $this->activeModelResolver->getActiveModel($runId);
        $runtimeSettings = $this->compactionConfig->resolveRuntimeSettings($activeModelStr);
        $resolvedModel = $runtimeSettings->model ?? $activeModelStr;

        if (null === $resolvedModel || '' === trim($resolvedModel)) {
            throw new ForkCompactionSummarizationException('Fork launch could not compact parent context: no summarization model is available.', ForkCompactionFailureReasonEnum::NoSummarizationModel, hint: 'Set compaction.model in Hatfield settings, choose a parent session model with /model, or pass fork model explicitly.');
        }

        $thinkingLevel = $runtimeSettings->thinkingLevel;
        $modelOptions = null !== $thinkingLevel && '' !== $thinkingLevel
            ? ['thinking_level' => $thinkingLevel]
            : [];

        $preparation = $this->resolvePreparation($messages, $force);

        try {
            $summaryText = $this->summarizePreparation($preparation, $resolvedModel, $modelOptions);
        } catch (ForkCompactionSummarizationException $exception) {
            throw new ForkCompactionSummarizationException('Fork launch requires compacted parent context but summarization failed: '.$exception->getMessage(), $exception->reason(), hint: $exception->hint() ?? 'Check compaction.model, parent session model, and LLM availability, then retry fork.', previous: $exception);
        }

        $prepareResult = $this->toPrepareResult($preparation);
        $compactResult = $this->compactionService->buildCompactedMessages($summaryText, $prepareResult);

        return new VirtualCompactionResult(
            compactedMessages: $compactResult->compactedMessages,
            compacted: true,
            summaryText: $summaryText,
            summarizedCount: $compactResult->messagesCompacted,
        );
    }

    /**
     * @param list<AgentMessage> $messages
     */
    private function resolvePreparation(array $messages, bool $force): CompactionPreparationDTO
    {
        $result = $this->sessionCompactor->prepare($messages, $this->compactionConfig);

        if ($result->isReady()) {
            return $result->preparation;
        }

        if (!$force) {
            throw new ForkCompactionSummarizationException('Compaction preparation failed: '.($result->skipReason->value ?? 'unknown'), ForkCompactionFailureReasonEnum::PreparationFailed);
        }

        return $this->buildForcedPreparation($messages, $result->skipReason);
    }

    /**
     * @param list<AgentMessage> $messages
     */
    private function buildForcedPreparation(array $messages, ?CompactionSkipReasonEnum $skipReason): CompactionPreparationDTO
    {
        $prologueCount = 0;
        $prologue = [];
        $totalMessages = \count($messages);
        for ($i = 0; $i < $totalMessages; ++$i) {
            $role = $messages[$i]->role;
            if ('system' === $role || 'user-context' === $role) {
                $prologue[] = $messages[$i];
                ++$prologueCount;
            } else {
                break;
            }
        }

        $body = \array_slice($messages, $prologueCount);
        $bodyCount = \count($body);

        if (0 === $bodyCount) {
            throw new ForkCompactionSummarizationException('Fork launch requires compacted parent context but no compactable messages remain after prologue extraction.', ForkCompactionFailureReasonEnum::NoCompactableMessages);
        }

        if (1 === $bodyCount) {
            return new CompactionPreparationDTO(
                messagesToSummarize: $body,
                retainedTailMessages: $prologue,
                tokenEstimateBefore: $this->tokenEstimator->estimateTokens($messages),
                messagesCompacted: 1,
                messagesRetained: \count($prologue),
                firstRetainedIndex: $prologueCount,
                priorSummaryPresent: $this->bodyContainsPriorCompactSummary($body),
            );
        }

        if (CompactionSkipReasonEnum::TooFewMessages === $skipReason) {
            return new CompactionPreparationDTO(
                messagesToSummarize: $body,
                retainedTailMessages: $prologue,
                tokenEstimateBefore: $this->tokenEstimator->estimateTokens($messages),
                messagesCompacted: $bodyCount,
                messagesRetained: \count($prologue),
                firstRetainedIndex: $prologueCount,
                priorSummaryPresent: $this->bodyContainsPriorCompactSummary($body),
            );
        }

        $boundary = $this->boundarySelector->findForcedSafeBoundary($body);
        if (null === $boundary) {
            throw new ForkCompactionSummarizationException('Fork launch requires compacted parent context but no safe compaction boundary exists for the parent message sequence.', ForkCompactionFailureReasonEnum::NoSafeBoundary);
        }

        if (0 === $boundary) {
            $messagesToSummarize = $body;
            $retainedTail = $prologue;
            $firstRetainedIndex = $prologueCount;
        } else {
            $messagesToSummarize = \array_slice($body, 0, $boundary);
            $retainedBodyTail = \array_slice($body, $boundary);
            $retainedTail = [...$prologue, ...$retainedBodyTail];
            $firstRetainedIndex = $prologueCount + $boundary;
        }

        return new CompactionPreparationDTO(
            messagesToSummarize: $messagesToSummarize,
            retainedTailMessages: $retainedTail,
            tokenEstimateBefore: $this->tokenEstimator->estimateTokens($messages),
            messagesCompacted: \count($messagesToSummarize),
            messagesRetained: \count($retainedTail),
            firstRetainedIndex: $firstRetainedIndex,
            priorSummaryPresent: $this->bodyContainsPriorCompactSummary($messagesToSummarize),
        );
    }

    /**
     * @param list<AgentMessage> $body
     */
    private function bodyContainsPriorCompactSummary(array $body): bool
    {
        foreach ($body as $message) {
            if (true === ($message->metadata['compact_summary'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $modelOptions
     */
    private const INEFFECTIVE_RETRY_INSTRUCTION = 'Your previous summary was too long and did not reduce context size. Produce a materially shorter, denser summary. Omit repetition and keep only decisions, constraints, file paths, and unresolved work.';

    /**
     * @param array<string, mixed> $modelOptions
     */
    private function summarizePreparation(
        CompactionPreparationDTO $preparation,
        string $resolvedModel,
        array $modelOptions,
    ): string {
        if ($this->isTrivialSingleMessageCompaction($preparation)) {
            return $this->deriveTrivialSummaryText($preparation);
        }

        try {
            return $this->invokeSummarizationAttempt(
                $preparation,
                $resolvedModel,
                $modelOptions,
                null,
                1,
            );
        } catch (ForkCompactionSummarizationException $exception) {
            if (ForkCompactionFailureReasonEnum::IneffectiveSummary !== $exception->reason()) {
                throw $exception;
            }
        }

        return $this->invokeSummarizationAttempt(
            $preparation,
            $resolvedModel,
            $modelOptions,
            self::INEFFECTIVE_RETRY_INSTRUCTION,
            2,
        );
    }

    /**
     * @param array<string, mixed> $modelOptions
     */
    private function invokeSummarizationAttempt(
        CompactionPreparationDTO $preparation,
        string $resolvedModel,
        array $modelOptions,
        ?string $customInstructions,
        int $attempt,
    ): string {
        $prepareResult = $this->toPrepareResult($preparation);
        $summarizationMessages = $this->compactionService->buildSummarizationMessages($prepareResult, $customInstructions);

        $this->logger->info('Fork virtual compaction summarization started.', [
            'event_type' => 'fork.compaction.summarize.started',
            'model' => $resolvedModel,
            'attempt' => $attempt,
            'messages_to_summarize' => $preparation->messagesCompacted,
            'messages_retained' => $preparation->messagesRetained,
        ]);

        $response = $this->platform->invoke(new ModelInvocationRequest(
            model: $resolvedModel,
            input: new ModelInvocationInput(
                runId: 'fork-virtual-compaction',
                turnNo: 0,
                stepId: 'fork-virtual-compaction',
                messages: $summarizationMessages,
            ),
            options: new ModelInvocationOptions(
                toolsEnabled: false,
                extraOptions: $modelOptions,
                streamObserverEnabled: false,
            ),
        ));

        if (null !== $response->error) {
            $message = $response->error['message'] ?? $response->error['user_message'] ?? 'unknown error';

            throw new ForkCompactionSummarizationException('Fork compaction summarization failed: '.(\is_string($message) ? $message : 'unknown error'), ForkCompactionFailureReasonEnum::SummarizationPlatformFailed);
        }

        $summaryText = $response->assistantMessage?->asText();
        if (null === $summaryText || '' === trim($summaryText)) {
            throw new ForkCompactionSummarizationException('Fork compaction summarization returned empty summary text.', ForkCompactionFailureReasonEnum::EmptySummaryText);
        }

        $compactResult = $this->compactionService->buildCompactedMessages($summaryText, $prepareResult);

        if ($preparation->messagesCompacted >= 2
            && $compactResult->tokenEstimateAfter >= $compactResult->tokenEstimateBefore) {
            throw new ForkCompactionSummarizationException('Fork compaction summarization was ineffective (context did not shrink).', ForkCompactionFailureReasonEnum::IneffectiveSummary);
        }

        $this->logger->info('Fork virtual compaction summarization completed.', [
            'event_type' => 'fork.compaction.summarize.completed',
            'model' => $resolvedModel,
            'attempt' => $attempt,
            'estimated_tokens_before' => $compactResult->tokenEstimateBefore,
            'estimated_tokens_after' => $compactResult->tokenEstimateAfter,
        ]);

        return $summaryText;
    }

    private function toPrepareResult(CompactionPreparationDTO $preparation): CompactionPrepareResult
    {
        return CompactionPrepareResult::ready(
            messagesToSummarize: $preparation->messagesToSummarize,
            retainedTailMessages: $preparation->retainedTailMessages,
            tokenEstimateBefore: $preparation->tokenEstimateBefore,
            messagesCompacted: $preparation->messagesCompacted,
            messagesRetained: $preparation->messagesRetained,
            firstRetainedIndex: $preparation->firstRetainedIndex,
            priorSummaryPresent: $preparation->priorSummaryPresent,
        );
    }

    private function isTrivialSingleMessageCompaction(CompactionPreparationDTO $preparation): bool
    {
        if (1 !== $preparation->messagesCompacted || 1 !== \count($preparation->messagesToSummarize)) {
            return false;
        }

        foreach ($preparation->retainedTailMessages as $message) {
            if ('system' !== $message->role && 'user-context' !== $message->role) {
                return false;
            }
        }

        return true;
    }

    private function deriveTrivialSummaryText(CompactionPreparationDTO $preparation): string
    {
        $message = $preparation->messagesToSummarize[0];
        $text = '';
        foreach ($message->content as $part) {
            if (\is_array($part) && isset($part['text']) && \is_string($part['text'])) {
                $text .= $part['text'];
            }
        }

        $text = trim($text);
        if ('' === $text) {
            throw new ForkCompactionSummarizationException('Fork compaction summarization returned empty summary text.', ForkCompactionFailureReasonEnum::EmptySummaryText);
        }

        return $text;
    }
}
