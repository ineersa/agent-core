<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

/**
 * Known subagent child run surfaced in the parent TUI for interactive live view and picker rows.
 *
 * Model and reasoning are concrete launch identity (non-empty) for live footer/border.
 */
final readonly class SubagentLiveChildDTO
{
    public string $model;
    public string $reasoning;

    public function __construct(
        public string $agentRunId,
        public string $artifactId,
        public string $agentName,
        public SubagentLiveStatusEnum $status,
        public string $taskSummary,
        public int $lastActivityAtMs,
        string $model,
        string $reasoning,
        public int $latestInputTokens = 0,
        public int $contextWindow = 0,
    ) {
        $model = trim($model);
        $reasoning = trim($reasoning);
        if ('' === $model || '' === $reasoning) {
            throw new \InvalidArgumentException('Subagent live child requires non-empty model and reasoning.');
        }
        $this->model = $model;
        $this->reasoning = $reasoning;
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
