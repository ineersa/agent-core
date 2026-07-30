<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\CodingAgent\Compaction\CompactionHookContextDTO;
use Ineersa\CodingAgent\Compaction\CompactionHookDispatcher;
use Ineersa\CodingAgent\Compaction\CompactionHookResultDTO;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookContextDTO;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookResultDTO;
use Psr\Log\LoggerInterface;

/**
 * Aggregates public ExtensionApi before-compaction hooks for CompactRun only.
 *
 * Internal tagged hooks stay on {@see CompactionHookDispatcher} and continue
 * best-effort isolation. Public extension hooks fail closed: an exception is
 * converted to an actionable cancel so CompactRun never silently falls through
 * to summary-mode LLM compaction after an extension failure.
 */
final readonly class ExtensionCompactionHookDispatcher
{
    public function __construct(
        private ExtensionHookRegistry $hookRegistry,
        private CompactionHookDispatcher $internalHookDispatcher,
        private LoggerInterface $logger,
        private ?ChildRunExtensionAllowlistReaderInterface $extensionAllowlistReader = null,
    ) {
    }

    /**
     * Dispatch internal tagged hooks, then public extension hooks.
     *
     * Aggregation rules match CompactionHookDispatcher:
     * cancel first wins; first non-empty replacement wins; instructions append;
     * metadata shallow-merges. Extension exceptions cancel the run path.
     */
    public function dispatchForCompactRun(
        CompactionHookContextDTO $internalContext,
        int $requiredStartSeq,
        int $requiredEndSeq,
    ): CompactionHookResultDTO {
        $merged = $this->internalHookDispatcher->dispatch($internalContext);
        if ($merged->cancels()) {
            return $merged;
        }

        $publicContext = new BeforeCompactionHookContextDTO(
            runId: $internalContext->runId,
            turnNo: $internalContext->turnNo,
            trigger: $internalContext->trigger,
            requiredStartSeq: $requiredStartSeq,
            requiredEndSeq: $requiredEndSeq,
            tokenEstimateBefore: $internalContext->tokenEstimateBefore,
            messagesCompacted: $internalContext->messagesCompacted,
            messagesRetained: $internalContext->messagesRetained,
            firstRetainedIndex: $internalContext->firstRetainedIndex,
            priorSummaryPresent: $internalContext->priorSummaryPresent,
            customInstructions: $internalContext->customInstructions,
            resolvedModel: $internalContext->resolvedModel,
            thinkingLevel: $internalContext->thinkingLevel,
        );

        $allowed = $this->extensionAllowlistReader?->readAllowedExtensions($internalContext->runId);

        foreach ($this->hookRegistry->beforeCompactionHooks($allowed) as $hook) {
            try {
                $result = $hook->beforeCompaction($publicContext);
                $this->mergePublicResult($merged, $result);
                if ($merged->cancels()) {
                    return $merged;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Public before-compaction extension hook failed closed.', [
                    'component' => 'compaction',
                    'event_type' => 'compaction.extension_hook.error',
                    'run_id' => $internalContext->runId,
                    'turn_no' => $internalContext->turnNo,
                    'hook_class' => $hook::class,
                    'exception_class' => $e::class,
                ]);

                $merged->cancelReason = 'extension_hook_failed: '.$hook::class;
                $merged->metadata = [
                    ...$merged->metadata,
                    'extension_hook_error' => true,
                    'extension_hook_class' => $hook::class,
                ];

                return $merged;
            }
        }

        return $merged;
    }

    private function mergePublicResult(CompactionHookResultDTO $merged, BeforeCompactionHookResultDTO $result): void
    {
        if ($result->cancels()) {
            $merged->cancelReason = $result->cancelReason;
            $merged->metadata = [...$merged->metadata, ...$result->metadata];

            return;
        }

        if (null === $merged->replacementSummary && $result->hasReplacementSummary()) {
            $merged->replacementSummary = $result->replacementSummary;
        }

        if ($result->hasAdditionalInstructions()) {
            $merged->additionalInstructions = null !== $merged->additionalInstructions
                ? $merged->additionalInstructions."\n".$result->additionalInstructions
                : $result->additionalInstructions;
        }

        $merged->metadata = [...$merged->metadata, ...$result->metadata];
    }
}
