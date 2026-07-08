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
use Ineersa\CodingAgent\Agent\Fork\ForkCompactionSummarizationException;
use Ineersa\CodingAgent\Config\CompactionConfig;
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
            throw new ForkCompactionSummarizationException('Fork launch could not compact parent context: no summarization model is available.', hint: 'Set compaction.model in Hatfield settings, choose a parent session model with /model, or pass fork model explicitly.');
        }

        $thinkingLevel = $runtimeSettings->thinkingLevel;
        $modelOptions = null !== $thinkingLevel && '' !== $thinkingLevel
            ? ['thinking_level' => $thinkingLevel]
            : [];

        $preparation = $this->resolvePreparation($messages, $force);

        try {
            $summaryText = $this->summarizePreparation($preparation, $resolvedModel, $modelOptions);
        } catch (ForkCompactionSummarizationException $exception) {
            throw new ForkCompactionSummarizationException('Fork launch requires compacted parent context but summarization failed: '.$exception->getMessage(), hint: $exception->hint() ?? 'Check compaction.model, parent session model, and LLM availability, then retry fork.', previous: $exception);
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
            throw new ForkCompactionSummarizationException('Compaction preparation failed: '.($result->skipReason->value ?? 'unknown'));
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
            throw new ForkCompactionSummarizationException('Fork launch requires compacted parent context but no compactable messages remain after prologue extraction.');
        }

        if (1 === $bodyCount) {
            return new CompactionPreparationDTO(
                messagesToSummarize: $body,
                retainedTailMessages: $prologue,
                tokenEstimateBefore: 1,
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
                tokenEstimateBefore: max(2, $bodyCount),
                messagesCompacted: $bodyCount,
                messagesRetained: \count($prologue),
                firstRetainedIndex: $prologueCount,
                priorSummaryPresent: $this->bodyContainsPriorCompactSummary($body),
            );
        }

        $retainedTail = [...$prologue, $body[\count($body) - 1]];
        $messagesToSummarize = \array_slice($body, 0, -1);

        if ([] === $messagesToSummarize) {
            $messagesToSummarize = $body;
            $retainedTail = $prologue;
        }

        return new CompactionPreparationDTO(
            messagesToSummarize: $messagesToSummarize,
            retainedTailMessages: $retainedTail,
            tokenEstimateBefore: max(2, \count($messages)),
            messagesCompacted: \count($messagesToSummarize),
            messagesRetained: \count($retainedTail),
            firstRetainedIndex: $prologueCount + \count($body) - 1,
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
    private function summarizePreparation(
        CompactionPreparationDTO $preparation,
        string $resolvedModel,
        array $modelOptions,
    ): string {
        if ($this->isTrivialSingleMessageCompaction($preparation)) {
            return $this->deriveTrivialSummaryText($preparation);
        }

        $prepareResult = $this->toPrepareResult($preparation);
        $summarizationMessages = $this->compactionService->buildSummarizationMessages($prepareResult, null);

        $this->logger->info('Fork virtual compaction summarization started.', [
            'event_type' => 'fork.compaction.summarize.started',
            'model' => $resolvedModel,
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

            throw new ForkCompactionSummarizationException('Fork compaction summarization failed: '.(\is_string($message) ? $message : 'unknown error'));
        }

        $summaryText = $response->assistantMessage?->asText();
        if (null === $summaryText || '' === trim($summaryText)) {
            throw new ForkCompactionSummarizationException('Fork compaction summarization returned empty summary text.');
        }

        $compactResult = $this->compactionService->buildCompactedMessages($summaryText, $prepareResult);

        if ($preparation->messagesCompacted >= 2
            && $compactResult->tokenEstimateAfter >= $compactResult->tokenEstimateBefore) {
            throw new ForkCompactionSummarizationException('Fork compaction summarization was ineffective (context did not shrink).');
        }

        $this->logger->info('Fork virtual compaction summarization completed.', [
            'event_type' => 'fork.compaction.summarize.completed',
            'model' => $resolvedModel,
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
            throw new ForkCompactionSummarizationException('Fork compaction summarization returned empty summary text.');
        }

        return $text;
    }
}
