<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;

/**
 * Indexes subagent child runs from parent runtime subagent_progress payloads.
 */
final class SubagentLiveCatalog
{
    /** @var array<string, SubagentLiveChildDTO> artifactId → child */
    private array $byArtifactId = [];

    /** @var array<string, true> */
    private array $dismissedArtifactIds = [];

    public function dismissArtifactId(string $artifactId): ?SubagentLiveChildDTO
    {
        $artifactId = trim($artifactId);
        if ('' === $artifactId) {
            return null;
        }

        $existing = $this->byArtifactId[$artifactId] ?? null;
        $this->dismissedArtifactIds[$artifactId] = true;
        unset($this->byArtifactId[$artifactId]);

        return $existing;
    }

    public function isDismissed(string $artifactId): bool
    {
        return isset($this->dismissedArtifactIds[trim($artifactId)]);
    }

    /**
     * @return list<SubagentLiveChildDTO>
     */
    public function all(): array
    {
        $items = array_values($this->byArtifactId);
        usort($items, static function (SubagentLiveChildDTO $a, SubagentLiveChildDTO $b): int {
            if ($a->needsAttention() !== $b->needsAttention()) {
                return $b->needsAttention() <=> $a->needsAttention();
            }

            return $b->lastActivityAtMs <=> $a->lastActivityAtMs;
        });

        return $items;
    }

    public function firstChildNeedingAttention(): ?SubagentLiveChildDTO
    {
        foreach ($this->all() as $child) {
            if ($child->needsAttention()) {
                return $child;
            }
        }

        return null;
    }

    public function findByArtifactId(string $artifactId): ?SubagentLiveChildDTO
    {
        return $this->byArtifactId[$artifactId] ?? null;
    }

    /**
     * Optimistic catalog update when the TUI knows a child left waiting_human
     * before the next parent subagent_progress event (answer, dismiss, cancel).
     */
    public function applyChildStatus(string $artifactId, SubagentLiveStatusEnum $status): void
    {
        $existing = $this->byArtifactId[$artifactId] ?? null;
        if (null === $existing) {
            return;
        }

        $this->byArtifactId[$artifactId] = new SubagentLiveChildDTO(
            agentRunId: $existing->agentRunId,
            artifactId: $existing->artifactId,
            agentName: $existing->agentName,
            status: $status,
            taskSummary: $existing->taskSummary,
            lastActivityAtMs: (int) (microtime(true) * 1000),
            model: $existing->model,
            latestInputTokens: $existing->latestInputTokens,
            contextWindow: $existing->contextWindow,
        );
    }

    public function ingestRuntimeEvent(RuntimeEvent $event): void
    {
        if (!str_contains($event->type, 'tool_execution')) {
            return;
        }

        $progress = $event->payload['subagent_progress'] ?? null;
        if (!\is_array($progress)) {
            return;
        }

        $now = (int) (microtime(true) * 1000);
        $mode = (string) ($progress['mode'] ?? 'single');

        if ('parallel' === $mode) {
            $children = $progress['children'] ?? [];
            if (!\is_array($children)) {
                return;
            }
            foreach ($children as $child) {
                if (!\is_array($child)) {
                    continue;
                }
                $this->upsertFromProgressRow($child, $now);
            }

            return;
        }

        $this->upsertFromProgressRow($progress, $now);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function upsertFromProgressRow(array $row, int $now): void
    {
        $artifactId = trim((string) ($row['artifact_id'] ?? ''));
        if ('' === $artifactId || $this->isDismissed($artifactId)) {
            return;
        }

        $agentRunId = trim((string) ($row['agent_run_id'] ?? ''));
        $agentName = trim((string) ($row['agent_name'] ?? 'subagent'));
        $status = SubagentLiveStatusEnum::fromProgressString((string) ($row['status'] ?? 'running'));
        $taskSummary = trim((string) ($row['task_summary'] ?? ''));

        $model = $this->optionalString($row['model'] ?? null);
        $latestInputTokens = $this->optionalPositiveInt($row['latest_input_tokens'] ?? null);
        $contextWindow = $this->optionalPositiveInt($row['context_window'] ?? null);

        if ('' === $agentRunId) {
            $existing = $this->byArtifactId[$artifactId] ?? null;
            $agentRunId = null !== $existing ? $existing->agentRunId : '';
        }

        if ('' === $agentRunId) {
            return;
        }

        $existing = $this->byArtifactId[$artifactId] ?? null;
        if (null !== $existing && $existing->status->isTerminal() && !$status->isTerminal()) {
            // Stale in-flight progress rows must not downgrade terminal/cancelled catalog entries.
            return;
        }

        if (null === $model && null !== $existing) {
            $model = $existing->model;
        }
        if (0 === $latestInputTokens && null !== $existing) {
            $latestInputTokens = $existing->latestInputTokens;
        }
        if (0 === $contextWindow && null !== $existing) {
            $contextWindow = $existing->contextWindow;
        }

        $this->byArtifactId[$artifactId] = new SubagentLiveChildDTO(
            agentRunId: $agentRunId,
            artifactId: $artifactId,
            agentName: $agentName,
            status: $status,
            taskSummary: $taskSummary,
            lastActivityAtMs: $now,
            model: $model,
            latestInputTokens: $latestInputTokens,
            contextWindow: $contextWindow,
        );
    }

    private function optionalString(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return '' !== $trimmed ? $trimmed : null;
    }

    private function optionalPositiveInt(mixed $value): int
    {
        if (!is_numeric($value)) {
            return 0;
        }
        $int = (int) $value;

        return $int > 0 ? $int : 0;
    }
}
