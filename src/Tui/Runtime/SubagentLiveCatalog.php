<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Projection\SubagentProgressChildRowDTO;
use Ineersa\CodingAgent\Runtime\Projection\SubagentProgressSnapshotDTO;
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

        // Terminal catalog rows are monotonic: never re-open as running/waiting_human.
        if ($existing->status->isTerminal() && !$status->isTerminal()) {
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

        // Wire/public boundary: denormalize once, then use typed properties.
        $snapshot = SubagentProgressSnapshotDTO::fromArray($progress);
        $now = (int) (microtime(true) * 1000);

        if ($snapshot->isParallel()) {
            foreach ($snapshot->children as $child) {
                $this->upsertFromChildRow($child, $now);
            }

            return;
        }

        $this->upsertFromSingleSnapshot($snapshot, $now);
    }

    private function upsertFromSingleSnapshot(SubagentProgressSnapshotDTO $row, int $now): void
    {
        $artifactId = trim($row->artifactId ?? '');
        if ('' === $artifactId || $this->isDismissed($artifactId)) {
            return;
        }

        $agentRunId = trim($row->agentRunId ?? '');
        $agentName = trim($row->agentName ?? 'subagent');
        if ('' === $agentName) {
            $agentName = 'subagent';
        }
        $status = SubagentLiveStatusEnum::fromProgressString($row->status);
        $taskSummary = trim($row->taskSummary ?? '');
        $model = $row->model;
        $latestInputTokens = (null !== $row->latestInputTokens && $row->latestInputTokens > 0) ? $row->latestInputTokens : 0;
        $contextWindow = (null !== $row->contextWindow && $row->contextWindow > 0) ? $row->contextWindow : 0;

        $this->upsertCatalogRow(
            artifactId: $artifactId,
            agentRunId: $agentRunId,
            agentName: $agentName,
            status: $status,
            taskSummary: $taskSummary,
            now: $now,
            model: $model,
            latestInputTokens: $latestInputTokens,
            contextWindow: $contextWindow,
        );
    }

    private function upsertFromChildRow(SubagentProgressChildRowDTO $row, int $now): void
    {
        $artifactId = trim($row->artifactId);
        if ('' === $artifactId || $this->isDismissed($artifactId)) {
            return;
        }

        $agentRunId = trim($row->agentRunId);
        $agentName = trim($row->agentName);
        if ('' === $agentName) {
            $agentName = 'subagent';
        }
        $status = SubagentLiveStatusEnum::fromProgressString($row->status);
        $taskSummary = trim($row->taskSummary);
        $model = $row->model;
        $latestInputTokens = (null !== $row->latestInputTokens && $row->latestInputTokens > 0) ? $row->latestInputTokens : 0;
        $contextWindow = (null !== $row->contextWindow && $row->contextWindow > 0) ? $row->contextWindow : 0;

        $this->upsertCatalogRow(
            artifactId: $artifactId,
            agentRunId: $agentRunId,
            agentName: $agentName,
            status: $status,
            taskSummary: $taskSummary,
            now: $now,
            model: $model,
            latestInputTokens: $latestInputTokens,
            contextWindow: $contextWindow,
        );
    }

    private function upsertCatalogRow(
        string $artifactId,
        string $agentRunId,
        string $agentName,
        SubagentLiveStatusEnum $status,
        string $taskSummary,
        int $now,
        ?string $model,
        int $latestInputTokens,
        int $contextWindow,
    ): void {
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
}
