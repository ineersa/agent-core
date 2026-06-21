<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

/**
 * Result from a single {@see BeforeCompactionHookInterface::beforeCompaction()} call.
 *
 * Aggregated by {@see CompactionHookDispatcher} across all registered hooks.
 *
 * Semantics:
 *  - cancelReason: non-null → stop iterating, emit context_compaction_failed.
 *  - replacementSummary: non-empty non-null → skip LLM, use this text as summary;
 *    only the first non-empty replacement is kept.
 *  - additionalInstructions: appended to existing custom instructions.
 *  - metadata: shallow-merged across hooks; must be JSON-safe (scalar/list/map).
 */
final class CompactionHookResultDTO
{
    /**
     * @param array<string, mixed> $metadata JSON-safe hook metadata
     */
    public function __construct(
        public ?string $cancelReason = null,
        public ?string $replacementSummary = null,
        public ?string $additionalInstructions = null,
        public array $metadata = [],
    ) {
    }

    /**
     * Convenience: identity result (no-op).
     */
    public static function continue(): self
    {
        return new self();
    }

    /**
     * Convenience: cancel compaction with the given reason.
     *
     * The reason appears in the context_compaction_failed event payload
     * as $payload['reason'] prefixed with 'hook_cancelled:' for traceability.
     *
     * @param string $reason Human-readable cancel reason (e.g. "SafeGuard: user is blocked")
     */
    public static function cancel(string $reason): self
    {
        return new self(cancelReason: $reason);
    }

    /**
     * Convenience: skip the LLM call and use the given text as the summary.
     *
     * @param string $summaryText Replacement summary text (must be non-empty after trim)
     */
    public static function replaceSummary(string $summaryText): self
    {
        return new self(replacementSummary: $summaryText);
    }

    /**
     * Does this result cancel compaction?
     */
    public function cancels(): bool
    {
        return null !== $this->cancelReason;
    }

    /**
     * Does this result provide a non-empty replacement summary?
     */
    public function hasReplacementSummary(): bool
    {
        return null !== $this->replacementSummary && '' !== trim($this->replacementSummary);
    }

    /**
     * Are there any additional instructions to append?
     */
    public function hasAdditionalInstructions(): bool
    {
        return null !== $this->additionalInstructions && '' !== trim($this->additionalInstructions);
    }
}
