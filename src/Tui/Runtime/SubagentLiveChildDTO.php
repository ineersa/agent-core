<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

/**
 * Known subagent child run surfaced in the parent TUI for readonly live view.
 */
final readonly class SubagentLiveChildDTO
{
    public function __construct(
        public string $agentRunId,
        public string $artifactId,
        public string $agentName,
        public SubagentLiveStatusEnum $status,
        public string $taskSummary,
        public int $lastActivityAtMs,
    ) {
    }

    public function isRunning(): bool
    {
        return $this->status->isActive();
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function needsAttention(): bool
    {
        return $this->status->needsAttention();
    }

    public function statusLabel(): string
    {
        return $this->status->value;
    }
}
