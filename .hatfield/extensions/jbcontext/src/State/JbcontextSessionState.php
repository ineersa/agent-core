<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\State;

/**
 * Session-scoped jbcontext eligibility and refresh state.
 *
 * Written by background extension-agent jobs; read by tools and the TUI poller.
 *
 * checkGeneration increments on every controller session-start claim so a
 * restarted/resumed controller can recheck eligibility, while in-flight jobs
 * from a previous controller attempt ignore themselves when their generation
 * no longer matches.
 *
 * @phpstan-type StateArray array{
 *     session_id: string,
 *     mode: string,
 *     reason: ?string,
 *     attempt: int,
 *     started_at: float,
 *     reindex_pending: bool,
 *     reindex_running: bool,
 *     eligibility_started: bool,
 *     check_generation: int,
 *     updated_at: float
 * }
 */
final readonly class JbcontextSessionState
{
    public function __construct(
        public string $sessionId,
        public JbcontextSessionModeEnum $mode,
        public ?string $reason,
        public int $attempt,
        public float $startedAt,
        public bool $reindexPending,
        public bool $reindexRunning,
        public bool $eligibilityStarted,
        public int $checkGeneration,
        public float $updatedAt,
    ) {
        if ('' === trim($this->sessionId)) {
            throw new \InvalidArgumentException('JbcontextSessionState sessionId must be non-empty.');
        }
        if ($this->checkGeneration < 0) {
            throw new \InvalidArgumentException('JbcontextSessionState checkGeneration must be >= 0.');
        }
    }

    public static function pending(string $sessionId, ?float $now = null): self
    {
        $now ??= microtime(true);

        return new self(
            sessionId: $sessionId,
            mode: JbcontextSessionModeEnum::Pending,
            reason: null,
            attempt: 0,
            startedAt: $now,
            reindexPending: false,
            reindexRunning: false,
            eligibilityStarted: false,
            checkGeneration: 0,
            updatedAt: $now,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $sessionId = trim((string) ($data['session_id'] ?? ''));
        if ('' === $sessionId) {
            throw new \InvalidArgumentException('JbcontextSessionState requires session_id.');
        }

        $modeRaw = (string) ($data['mode'] ?? JbcontextSessionModeEnum::Pending->value);
        $mode = JbcontextSessionModeEnum::tryFrom($modeRaw) ?? JbcontextSessionModeEnum::Pending;
        $now = microtime(true);

        return new self(
            sessionId: $sessionId,
            mode: $mode,
            reason: isset($data['reason']) ? (string) $data['reason'] : null,
            attempt: max(0, (int) ($data['attempt'] ?? 0)),
            startedAt: (float) ($data['started_at'] ?? $now),
            reindexPending: (bool) ($data['reindex_pending'] ?? false),
            reindexRunning: (bool) ($data['reindex_running'] ?? false),
            eligibilityStarted: (bool) ($data['eligibility_started'] ?? false),
            checkGeneration: max(0, (int) ($data['check_generation'] ?? 0)),
            updatedAt: (float) ($data['updated_at'] ?? $now),
        );
    }

    /**
     * @return StateArray
     */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'mode' => $this->mode->value,
            'reason' => $this->reason,
            'attempt' => $this->attempt,
            'started_at' => $this->startedAt,
            'reindex_pending' => $this->reindexPending,
            'reindex_running' => $this->reindexRunning,
            'eligibility_started' => $this->eligibilityStarted,
            'check_generation' => $this->checkGeneration,
            'updated_at' => $this->updatedAt,
        ];
    }

    public function with(
        ?JbcontextSessionModeEnum $mode = null,
        ?string $reason = null,
        bool $clearReason = false,
        ?int $attempt = null,
        ?float $startedAt = null,
        ?bool $reindexPending = null,
        ?bool $reindexRunning = null,
        ?bool $eligibilityStarted = null,
        ?int $checkGeneration = null,
        ?float $updatedAt = null,
    ): self {
        return new self(
            sessionId: $this->sessionId,
            mode: $mode ?? $this->mode,
            reason: $clearReason ? null : ($reason ?? $this->reason),
            attempt: $attempt ?? $this->attempt,
            startedAt: $startedAt ?? $this->startedAt,
            reindexPending: $reindexPending ?? $this->reindexPending,
            reindexRunning: $reindexRunning ?? $this->reindexRunning,
            eligibilityStarted: $eligibilityStarted ?? $this->eligibilityStarted,
            checkGeneration: $checkGeneration ?? $this->checkGeneration,
            updatedAt: $updatedAt ?? microtime(true),
        );
    }

    public function elapsedSeconds(?float $now = null): float
    {
        $now ??= microtime(true);

        return max(0.0, $now - $this->startedAt);
    }
}
