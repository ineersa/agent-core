<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\DTO\BackgroundChildStatusDTO;
use Ineersa\CodingAgent\Runtime\DTO\SubagentProgressSnapshotDTO;

/**
 * Indexes subagent child runs from typed parent subagent_progress snapshots.
 *
 * RuntimeEvent payload arrays are denormalized once at the TUI RuntimeEvent
 * boundary ({@see TuiRuntimeEventApplier}) before reaching this catalog.
 *
 * Canonical snapshots always carry non-empty model/reasoning launch identity.
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

    public function findByArtifactId(string $artifactId): ?SubagentLiveChildDTO
    {
        return $this->byArtifactId[$artifactId] ?? null;
    }

    /** @param list<BackgroundChildStatusDTO> $children */
    public function synchronizeOperationalStatus(array $children): void
    {
        foreach ($children as $child) {
            if ($this->isDismissed($child->artifactId)) {
                continue;
            }
            $existing = $this->byArtifactId[$child->artifactId] ?? null;
            $status = SubagentLiveStatusEnum::fromProgressString($child->status);
            if (null !== $existing && $existing->status === $status && $existing->backgrounded === $child->backgrounded && $existing->taskSummary === $child->task) {
                continue;
            }
            // This refreshes only the catalog. The acknowledged transcript card
            // is historical and is never projected from these operational rows.
            $this->byArtifactId[$child->artifactId] = new SubagentLiveChildDTO(
                $child->runId, $child->artifactId, $child->name, $status, $child->task,
                (int) (microtime(true) * 1000), $child->model, $child->reasoning,
                $existing->latestInputTokens ?? 0, $existing->contextWindow ?? 0,
                $existing?->cacheReadHitPercentage, $child->backgrounded,
            );
        }
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
            reasoning: $existing->reasoning,
            latestInputTokens: $existing->latestInputTokens,
            contextWindow: $existing->contextWindow,
            cacheReadHitPercentage: $existing->cacheReadHitPercentage,
            backgrounded: $existing->backgrounded,
        );
    }

    public function ingestSnapshot(SubagentProgressSnapshotDTO $snapshot): void
    {
        $this->upsertFromProgressRow($snapshot, (int) (microtime(true) * 1000));
    }

    private function upsertFromProgressRow(SubagentProgressSnapshotDTO $row, int $now): void
    {
        $artifactId = $row->artifactId;
        if ('' === $artifactId || $this->isDismissed($artifactId)) {
            return;
        }

        $status = SubagentLiveStatusEnum::fromProgressString($row->status);
        $existing = $this->byArtifactId[$artifactId] ?? null;
        if (null !== $existing && $existing->status->isTerminal() && !$status->isTerminal()) {
            // Stale same-task in-flight progress must not reopen terminal rows.
            // Resume rebind updates task_summary; allow Running/WaitingHuman only when the task changed.
            $isResumeReopen = (SubagentLiveStatusEnum::Running === $status || SubagentLiveStatusEnum::WaitingHuman === $status)
                && $row->taskSummary !== $existing->taskSummary;
            if (!$isResumeReopen) {
                return;
            }
        }

        $latestInputTokens = $row->latestInputTokens > 0
            ? $row->latestInputTokens
            : (null !== $existing ? $existing->latestInputTokens : 0);
        $contextWindow = (null !== $row->contextWindow && $row->contextWindow > 0)
            ? $row->contextWindow
            : (null !== $existing ? $existing->contextWindow : 0);

        $this->byArtifactId[$artifactId] = new SubagentLiveChildDTO(
            agentRunId: $row->agentRunId,
            artifactId: $artifactId,
            agentName: $row->agentName,
            status: $status,
            taskSummary: $row->taskSummary,
            lastActivityAtMs: $now,
            model: $row->model,
            reasoning: $row->reasoning,
            latestInputTokens: $latestInputTokens,
            contextWindow: $contextWindow,
            cacheReadHitPercentage: $row->cacheReadHitPercentage,
            backgrounded: $existing->backgrounded ?? false,
        );
    }
}
