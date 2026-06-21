<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Aggregates before-compaction hooks and returns a merged result.
 *
 * Hooks are injected via !tagged_iterator coding_agent.before_compaction_hook
 * and invoked in registration order.
 *
 * Aggregation rules:
 *  - cancel: first hook that cancels wins — remaining hooks are skipped.
 *  - replacement summary: first non-empty replacement summary wins; later
 *    replacement summaries are ignored. Once a replacement is found, hooks
 *    continue ONLY for cancel and additional instructions.
 *  - additional instructions: each hook's instructions are appended in order.
 *  - metadata: shallow-merged in order (later keys overwrite earlier).
 *  - exceptions from a single hook are caught, logged as warnings, and do NOT
 *    stop later hooks from running.
 */
final readonly class CompactionHookDispatcher
{
    /**
     * @param iterable<BeforeCompactionHookInterface> $hooks
     */
    public function __construct(
        private iterable $hooks,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Dispatch all registered before-compaction hooks and return the
     * aggregated result.
     *
     * Best-effort: a failing hook logs a warning and continues.
     */
    public function dispatch(CompactionHookContextDTO $context): CompactionHookResultDTO
    {
        $merged = new CompactionHookResultDTO();

        foreach ($this->hooks as $hook) {
            try {
                $result = $hook->beforeCompaction($context);

                // Cancel: first cancel wins, stop iterating.
                if ($result->cancels()) {
                    $merged->cancelReason = $result->cancelReason;
                    $merged->metadata = [...$merged->metadata, ...$result->metadata];

                    return $merged;
                }

                // Replacement summary: first non-empty wins.
                if (null === $merged->replacementSummary && $result->hasReplacementSummary()) {
                    $merged->replacementSummary = $result->replacementSummary;
                }

                // Additional instructions: append in order.
                if ($result->hasAdditionalInstructions()) {
                    $merged->additionalInstructions = null !== $merged->additionalInstructions
                        ? $merged->additionalInstructions."\n".$result->additionalInstructions
                        : $result->additionalInstructions;
                }

                // Metadata: shallow-merge (later values override earlier for same key).
                $merged->metadata = [...$merged->metadata, ...$result->metadata];
            } catch (\Throwable $e) {
                $this->logger->warning('Before-compaction hook threw an exception.', [
                    'component' => 'compaction',
                    'event_type' => 'compaction.hook.error',
                    'run_id' => $context->runId,
                    'turn_no' => $context->turnNo,
                    'exception' => $e->getMessage(),
                    'hook_class' => $hook::class,
                ]);
                // Continue with next hook — best-effort.
            }
        }

        return $merged;
    }
}
