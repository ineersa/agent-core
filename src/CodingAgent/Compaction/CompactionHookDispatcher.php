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
 *    replacement summaries are ignored.  Metadata and additional instructions
 *    from later hooks (including the replacement hook itself) continue to merge
 *    so that a hook can both replace the summary AND contribute metadata.
 *  - additional instructions: each hook's instructions are appended in order.
 *  - metadata: shallow-merged in order (later keys overwrite earlier);
 *    sanitised before event persistence via {@see sanitiseMetadata()}.
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

    /**
     * Sanitise hook metadata so only JSON-safe values reach event payloads
     * and persistence.  Objects, resources, and closures are dropped;
     * null, scalars, and array(list/map) values are retained.  Arrays are
     * recursed into so nested maps stay safe.
     *
     * Callers should pass hook metadata through this before attaching it
     * to any event payload or serialised transport message.
     *
     * @param array<string, mixed> $metadata Raw hook metadata (may contain objects etc)
     *
     * @return array<string, mixed> Sanitised metadata (only JSON-safe values)
     */
    public function sanitiseMetadata(array $metadata): array
    {
        if ([] === $metadata) {
            return [];
        }

        $safe = [];

        foreach ($metadata as $key => $value) {
            if (!\is_string($key)) {
                // Skip non-string keys — event payloads expect string-keyed maps.
                continue;
            }

            if (null === $value || \is_scalar($value)) {
                $safe[$key] = $value;

                continue;
            }

            if (\is_array($value)) {
                $safe[$key] = $this->sanitiseMetadataArray($value);

                continue;
            }

            // Drop objects, resources, closures, and anything else.
        }

        return $safe;
    }

    /**
     * Recursively sanitise an array value (list or map) to be JSON-safe.
     *
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private function sanitiseMetadataArray(array $value): array
    {
        $safe = [];

        foreach ($value as $key => $item) {
            if (null === $item || \is_scalar($item)) {
                $safe[$key] = $item;

                continue;
            }

            if (\is_array($item)) {
                $safe[$key] = $this->sanitiseMetadataArray($item);

                continue;
            }

            // Drop non-serialisable entries.
        }

        return $safe;
    }
}
