<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\State;

/**
 * Process-shared session state for jbcontext eligibility and TUI status.
 *
 * Written by background extension-agent jobs; read by the tool and TUI poller.
 *
 * @phpstan-type StateArray array{
 *     mode: string,
 *     reason: ?string,
 *     status_text: ?string,
 *     attempt: int,
 *     next_retry_at: ?float,
 *     reindex_pending: bool,
 *     reindex_running: bool,
 *     updated_at: float
 * }
 */
final readonly class JbcontextSessionState
{
    public function __construct(
        public JbcontextSessionModeEnum $mode,
        public ?string $reason,
        public ?string $statusText,
        public int $attempt,
        public ?float $nextRetryAt,
        public bool $reindexPending,
        public bool $reindexRunning,
        public float $updatedAt,
    ) {
    }

    public static function pending(): self
    {
        return new self(
            mode: JbcontextSessionModeEnum::Pending,
            reason: null,
            statusText: 'jbcontext: checking index…',
            attempt: 0,
            nextRetryAt: null,
            reindexPending: false,
            reindexRunning: false,
            updatedAt: microtime(true),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $modeRaw = (string) ($data['mode'] ?? JbcontextSessionModeEnum::Pending->value);
        $mode = JbcontextSessionModeEnum::tryFrom($modeRaw) ?? JbcontextSessionModeEnum::Pending;

        return new self(
            mode: $mode,
            reason: isset($data['reason']) ? (string) $data['reason'] : null,
            statusText: isset($data['status_text']) ? (string) $data['status_text'] : null,
            attempt: max(0, (int) ($data['attempt'] ?? 0)),
            nextRetryAt: isset($data['next_retry_at']) ? (float) $data['next_retry_at'] : null,
            reindexPending: (bool) ($data['reindex_pending'] ?? false),
            reindexRunning: (bool) ($data['reindex_running'] ?? false),
            updatedAt: (float) ($data['updated_at'] ?? microtime(true)),
        );
    }

    /**
     * @return StateArray
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'reason' => $this->reason,
            'status_text' => $this->statusText,
            'attempt' => $this->attempt,
            'next_retry_at' => $this->nextRetryAt,
            'reindex_pending' => $this->reindexPending,
            'reindex_running' => $this->reindexRunning,
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
        ?float $nextRetryAt = null,
        bool $clearNextRetryAt = false,
        ?bool $reindexPending = null,
        ?bool $reindexRunning = null,
    ): self {
        return new self(
            mode: $mode ?? $this->mode,
            reason: $clearReason ? null : ($reason ?? $this->reason),
            statusText: $clearStatusText ? null : ($statusText ?? $this->statusText),
            attempt: $attempt ?? $this->attempt,
            nextRetryAt: $clearNextRetryAt ? null : ($nextRetryAt ?? $this->nextRetryAt),
            reindexPending: $reindexPending ?? $this->reindexPending,
            reindexRunning: $reindexRunning ?? $this->reindexRunning,
            updatedAt: microtime(true),
        );
    }

    public function isSearchAvailable(): bool
    {
        return JbcontextSessionModeEnum::Eligible === $this->mode;
    }
}
