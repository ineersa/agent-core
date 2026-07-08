<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Contract\Compaction\CompactionPrepareResult;
use Ineersa\AgentCore\Contract\Compaction\CompactionServiceInterface;
use Ineersa\AgentCore\Contract\Model\PlatformInterface;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationOptions;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\CodingAgent\Compaction\CompactionPreparationDTO;
use Ineersa\CodingAgent\Config\CompactionConfig;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Fork-local compaction summarizer using the same prompt/partition machinery as /compact.
 *
 * Invokes {@see PlatformInterface} synchronously in the parent tool-call process.
 * Does not dispatch ExecuteCompactionStep, mutate parent RunState, or append parent events.
 */
final readonly class ForkCompactionSummarizer implements ForkSnapshotSummarizerInterface
{
    public function __construct(
        private CompactionServiceInterface $compactionService,
        private PlatformInterface $platform,
        private CompactionConfig $compactionConfig,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function summarize(CompactionPreparationDTO $preparation, ?string $activeSessionModel): string
    {
        if ($this->isTrivialSingleMessageCompaction($preparation)) {
            return $this->deriveTrivialSummaryText($preparation);
        }

        $runtimeSettings = $this->compactionConfig->resolveRuntimeSettings($activeSessionModel);
        $resolvedModel = $runtimeSettings->model ?? $activeSessionModel;

        if (null === $resolvedModel || '' === trim($resolvedModel)) {
            throw new ForkCompactionSummarizationException('Fork compaction requires an active session model or compaction.model override.');
        }

        $prepareResult = CompactionPrepareResult::ready(
            messagesToSummarize: $preparation->messagesToSummarize,
            retainedTailMessages: $preparation->retainedTailMessages,
            tokenEstimateBefore: $preparation->tokenEstimateBefore,
            messagesCompacted: $preparation->messagesCompacted,
            messagesRetained: $preparation->messagesRetained,
            firstRetainedIndex: $preparation->firstRetainedIndex,
            priorSummaryPresent: $preparation->priorSummaryPresent,
        );

        $summarizationMessages = $this->compactionService->buildSummarizationMessages($prepareResult, null);

        $thinkingLevel = $runtimeSettings->thinkingLevel;
        $modelOptions = null !== $thinkingLevel && '' !== $thinkingLevel
            ? ['thinking_level' => $thinkingLevel]
            : [];

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
