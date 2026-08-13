<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressChildRowDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressParallelSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSingleSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSnapshotInterface;

/**
 * Indexes subagent child runs from typed parent subagent_progress snapshots.
 *
 * RuntimeEvent payload arrays are denormalized once at the TUI RuntimeEvent
 * boundary ({@see TuiRuntimeEventApplier}) before reaching this catalog.
 *
 * Model and reasoning follow upstream launch-identity semantics: non-empty after trim.
 * Later progress rows may omit them; catalog preserves last known concrete values.
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
            reasoning: $existing->reasoning,
            latestInputTokens: $existing->latestInputTokens,
            contextWindow: $existing->contextWindow,
        );
    }

    public function ingestSnapshot(SubagentProgressSnapshotInterface $snapshot): void
    {
        // One wall-clock sample for the whole snapshot so multi-child parallel
        // rows share lastActivityAtMs (stable within-event tie; no product order change).
        $now = (int) (microtime(true) * 1000);

        if ($snapshot instanceof SubagentProgressParallelSnapshotDTO) {
            foreach ($snapshot->children as $child) {
                $this->upsertFromChildRow($child, $now);
            }

            return;
        }

        if ($snapshot instanceof SubagentProgressSingleSnapshotDTO) {
            $this->upsertFromSingleSnapshot($snapshot, $now);
        }
    }

    private function upsertFromSingleSnapshot(SubagentProgressSingleSnapshotDTO $row, int $now): void
    {
        $this->upsertCatalogRow(
            artifactId: trim($row->artifactId),
            agentRunId: trim($row->agentRunId),
            agentName: trim($row->agentName),
            status: SubagentLiveStatusEnum::fromProgressString($row->status),
            taskSummary: trim($row->taskSummary),
            now: $now,
            model: $this->optionalIdentityString($row->model),
            reasoning: $this->optionalIdentityString($row->reasoning),
            latestInputTokens: (null !== $row->latestInputTokens && $row->latestInputTokens > 0) ? $row->latestInputTokens : 0,
            contextWindow: (null !== $row->contextWindow && $row->contextWindow > 0) ? $row->contextWindow : 0,
        );
    }

    private function upsertFromChildRow(SubagentProgressChildRowDTO $row, int $now): void
    {
        $this->upsertCatalogRow(
            artifactId: trim($row->artifactId),
            agentRunId: trim($row->agentRunId),
            agentName: trim($row->agentName),
            status: SubagentLiveStatusEnum::fromProgressString($row->status),
            taskSummary: trim($row->taskSummary),
            now: $now,
            model: $this->optionalIdentityString($row->model),
            reasoning: $this->optionalIdentityString($row->reasoning),
            latestInputTokens: (null !== $row->latestInputTokens && $row->latestInputTokens > 0) ? $row->latestInputTokens : 0,
            contextWindow: (null !== $row->contextWindow && $row->contextWindow > 0) ? $row->contextWindow : 0,
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
        ?string $reasoning,
        int $latestInputTokens,
        int $contextWindow,
    ): void {
        if ('' === $artifactId || $this->isDismissed($artifactId)) {
            return;
        }

        if ('' === $agentName) {
            $agentName = 'subagent';
        }

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

        // Progress rows may omit model/reasoning on later ticks; preserve last known concrete values.
        if (null === $model) {
            $model = null !== $existing ? $existing->model : null;
        }
        if (null === $reasoning) {
            $reasoning = null !== $existing ? $existing->reasoning : null;
        }
        if (null === $model || null === $reasoning) {
            // Cannot create a live child without concrete launch identity.
            return;
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
            reasoning: $reasoning,
            latestInputTokens: $latestInputTokens,
            contextWindow: $contextWindow,
        );
    }

    private function optionalIdentityString(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $trimmed = trim($value);

        return '' !== $trimmed ? $trimmed : null;
    }
}
