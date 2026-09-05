<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\State;

/**
 * Session-scoped jbcontext eligibility and refresh state.
 *
 * Written by background extension-agent jobs; read by tools and the TUI poller.
 *
 * @phpstan-type StateArray array{
 *     session_id: string,
 *     mode: string,
 *     reason: ?string,
 *     status_text: ?string,
 *     attempt: int,
 *     started_at: float,
 *     reindex_pending: bool,
 *     reindex_running: bool,
 *     eligibility_started: bool,
 *     updated_at: float
 * }
 */
final readonly class JbcontextSessionState
{
    public function __construct(
        public string $sessionId,
        public JbcontextSessionModeEnum $mode,
        public ?string $reason,
        public ?string $statusText,
        public int $attempt,
        public float $startedAt,
        public bool $reindexPending,
        public bool $reindexRunning,
        public bool $eligibilityStarted,
        public float $updatedAt,
    ) {
        if ('' === trim($this->sessionId)) {
            throw new \InvalidArgumentException('JbcontextSessionState sessionId must be non-empty.');
        }
    }

    public static function pending(string $sessionId, ?float $now = null): self
    {
        $now ??= microtime(true);

        return new self(
            sessionId: $sessionId,
            mode: JbcontextSessionModeEnum::Pending,
            reason: null,
            statusText: 'jbcontext: checking index…',
            attempt: 0,
            startedAt: $now,
            reindexPending: false,
            reindexRunning: false,
            eligibilityStarted: false,
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
            statusText: isset($data['status_text']) ? (string) $data['status_text'] : null,
            attempt: max(0, (int) ($data['attempt'] ?? 0)),
            startedAt: (float) ($data['started_at'] ?? $now),
            reindexPending: (bool) ($data['reindex_pending'] ?? false),
            reindexRunning: (bool) ($data['reindex_running'] ?? false),
            eligibilityStarted: (bool) ($data['eligibility_started'] ?? false),
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
            'status_text' => $this->statusText,
            'attempt' => $this->attempt,
            'started_at' => $this->startedAt,
            'reindex_pending' => $this->reindexPending,
            'reindex_running' => $this->reindexRunning,
            'eligibility_started' => $this->eligibilityStarted,
            'updated_at' => $this->updatedAt,
        ];
    }

    public function with(
        ?JbcontextSessionModeEnum $mode = null,
        ?string $reason = null,
        bool $clearReason = false,
        ?string $statusText = null,
        bool $clearStatusText = false,
        ?int $attempt = null,
        ?float $startedAt = null,
        ?bool $reindexPending = null,
        ?bool $reindexRunning = null,
        ?bool $eligibilityStarted = null,
        ?float $updatedAt = null,
    ): self {
        return new self(
            sessionId: $this->sessionId,
            mode: $mode ?? $this->mode,
            reason: $clearReason ? null : ($reason ?? $this->reason),
            statusText: $clearStatusText ? null : ($statusText ?? $this->statusText),
            attempt: $attempt ?? $this->attempt,
            startedAt: $startedAt ?? $this->startedAt,
            reindexPending: $reindexPending ?? $this->reindexPending,
            reindexRunning: $reindexRunning ?? $this->reindexRunning,
            eligibilityStarted: $eligibilityStarted ?? $this->eligibilityStarted,
            updatedAt: $updatedAt ?? microtime(true),
        );
    }

    public function elapsedSeconds(?float $now = null): float
    {
        $now ??= microtime(true);

        return max(0.0, $now - $this->startedAt);
    }
}
