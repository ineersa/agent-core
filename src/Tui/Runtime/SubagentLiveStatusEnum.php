<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

/**
 * Stable subagent progress status strings from parent subagent_progress payloads.
 */
enum SubagentLiveStatusEnum: string
{
    case Pending = 'pending';
    case Running = 'running';
    case WaitingHuman = 'waiting_human';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Done = 'done';
    case Unknown = 'unknown';

    public static function fromProgressString(string $status): self
    {
        $normalized = strtolower(trim($status));

        return self::tryFrom($normalized) ?? self::Unknown;
    }

    public function isActive(): bool
    {
        return \in_array($this, [self::Pending, self::Running, self::WaitingHuman], true);
    }

    public function isTerminal(): bool
    {
        return \in_array($this, [self::Completed, self::Failed, self::Cancelled, self::Done], true);
    }

    public function needsAttention(): bool
    {
        return self::WaitingHuman === $this;
    }

    /**
     * Map this subagent status to the TUI run-activity state.
     *
     * Forward status→activity mapping used by TickPollListener. Unknown maps to null
     * so callers can keep their current activity unchanged. The reverse
     * activity→status reconciliation stays separate (TickPollListener).
     */
    public function toActivity(): ?RunActivityStateEnum
    {
        return match ($this) {
            self::Pending, self::Running => RunActivityStateEnum::Running,
            self::WaitingHuman => RunActivityStateEnum::WaitingHuman,
            self::Completed, self::Done => RunActivityStateEnum::Completed,
            self::Failed => RunActivityStateEnum::Failed,
            self::Cancelled => RunActivityStateEnum::Cancelled,
            self::Unknown => null,
        };
    }
}
