<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

/**
 * Disposable retained-history projection for one run.
 *
 * Freshness is the committed canonical sequence, not cache TTL.
 * Pending prompt fields keep contiguous committed deltas correct across
 * RunStarted / follow_up and later TurnAdvanced commits.
 *
 * {@see $ready} is publication readiness. Ordinary lookups reject a not-ready
 * snapshot so an interrupted append/publication window cannot look healthy.
 *
 * Ordinary runtime consumers also read compact derived fields maintained here:
 * eligible auto-compaction usage, issued context-budget reminder keys,
 * applied shell idempotency keys, and RunStarted launch metadata. Raw event
 * payloads and full RunStarted message bodies are never retained.
 */
final readonly class HistoryProjectionSnapshot
{
    /**
     * @param list<array{seq: int, turn_no: int, type: string, created_at: string, summary: string}> $sanitizedEventTail
     * @param list<'early'|'urgent'>                                                                 $issuedReminderKeys
     * @param array<string, true>                                                                    $appliedShellIdempotencyKeys
     */
    public function __construct(
        public HistoryDTO $history,
        public int $lastSeq,
        public ?string $initialPrompt = null,
        public ?string $pendingHumanPrompt = null,
        public bool $ready = true,
        public int $eventCount = 0,
        public array $sanitizedEventTail = [],
        public ?int $eligibleAutoCompactionInputTokens = null,
        public array $issuedReminderKeys = [],
        public array $appliedShellIdempotencyKeys = [],
        public ?RunStartedLaunchProjection $runStartedLaunch = null,
    ) {
    }

    public function withReady(bool $ready): self
    {
        return new self(
            history: $this->history,
            lastSeq: $this->lastSeq,
            initialPrompt: $this->initialPrompt,
            pendingHumanPrompt: $this->pendingHumanPrompt,
            ready: $ready,
            eventCount: $this->eventCount,
            sanitizedEventTail: $this->sanitizedEventTail,
            eligibleAutoCompactionInputTokens: $this->eligibleAutoCompactionInputTokens,
            issuedReminderKeys: $this->issuedReminderKeys,
            appliedShellIdempotencyKeys: $this->appliedShellIdempotencyKeys,
            runStartedLaunch: $this->runStartedLaunch,
        );
    }
}
