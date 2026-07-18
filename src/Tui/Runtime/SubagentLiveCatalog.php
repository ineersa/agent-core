<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;

/**
 * Indexes subagent child runs from parent runtime subagent_progress payloads.
 *
 * Owns the durable-in-session needs-input latch for tool-local questions
 * (SafeGuard and similar). Latch is keyed by child agentRunId so stale
 * nonterminal parent progress cannot erase a pending tool question, while
 * terminal progress always clears and wins.
 */
final class SubagentLiveCatalog
{
    /** @var array<string, SubagentLiveChildDTO> artifactId → child */
    private array $byArtifactId = [];

    /** @var array<string, true> */
    private array $dismissedArtifactIds = [];

    /**
     * Pending needs-input latches keyed by child agentRunId.
     *
     * A latch may exist before the catalog row arrives (tool_question before
     * the first subagent_progress upsert). Nonterminal progress cannot clear it.
     *
     * @var array<string, true>
     */
    private array $needsInputLatchesByRunId = [];

    public function dismissArtifactId(string $artifactId): ?SubagentLiveChildDTO
    {
        $artifactId = trim($artifactId);
        if ('' === $artifactId) {
            return null;
        }

        $existing = $this->byArtifactId[$artifactId] ?? null;
        $this->dismissedArtifactIds[$artifactId] = true;
        unset($this->byArtifactId[$artifactId]);

        // Dismiss must not leave an orphan latch for a removed row.
        if (null !== $existing) {
            $this->clearNeedsInputLatch($existing->agentRunId);
        }

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

    public function findByAgentRunId(string $agentRunId): ?SubagentLiveChildDTO
    {
        $agentRunId = trim($agentRunId);
        if ('' === $agentRunId) {
            return null;
        }

        foreach ($this->byArtifactId as $child) {
            if ($child->agentRunId === $agentRunId) {
                return $child;
            }
        }

        return null;
    }

    /**
     * True while a tool-local needs-input latch is held for this child run.
     */
    public function isNeedsInputLatched(string $agentRunId): bool
    {
        $agentRunId = trim($agentRunId);

        return '' !== $agentRunId && isset($this->needsInputLatchesByRunId[$agentRunId]);
    }

    /**
     * Latch needs-input for a child run. Works even if the catalog row has not
     * arrived yet; when the row later upserts, nonterminal progress keeps WaitingHuman.
     */
    public function markNeedsInputForRun(string $agentRunId): void
    {
        $agentRunId = trim($agentRunId);
        if ('' === $agentRunId) {
            return;
        }

        $this->needsInputLatchesByRunId[$agentRunId] = true;

        $existing = $this->findByAgentRunId($agentRunId);
        if (null === $existing) {
            return;
        }

        if (SubagentLiveStatusEnum::WaitingHuman !== $existing->status) {
            $this->applyChildStatus($existing->artifactId, SubagentLiveStatusEnum::WaitingHuman);
        }
    }

    /**
     * Clear the needs-input latch. Safe when the row was already overwritten or
     * never existed; optionally restores Running only when still WaitingHuman.
     */
    public function clearNeedsInputForRun(string $agentRunId, bool $restoreRunningIfWaiting = true): void
    {
        $agentRunId = trim($agentRunId);
        if ('' === $agentRunId) {
            return;
        }

        $this->clearNeedsInputLatch($agentRunId);

        if (!$restoreRunningIfWaiting) {
            return;
        }

        $existing = $this->findByAgentRunId($agentRunId);
        if (null === $existing) {
            return;
        }

        if (SubagentLiveStatusEnum::WaitingHuman === $existing->status) {
            $this->applyChildStatus($existing->artifactId, SubagentLiveStatusEnum::Running);
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

        // Terminal optimistic updates must drop the latch so future nonterminal
        // progress cannot re-promote a cancelled/completed child to needs-input.
        if ($status->isTerminal()) {
            $this->clearNeedsInputLatch($existing->agentRunId);
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

        // Terminal progress always clears the latch and wins over needs-input.
        if ($status->isTerminal()) {
            $this->clearNeedsInputLatch($agentRunId);
        } elseif ($this->isNeedsInputLatched($agentRunId) && !$status->needsAttention()) {
            // Nonterminal progress (running/pending/unknown) cannot erase a pending
            // tool-question latch. Keep the catalog row as WaitingHuman until answer,
            // cancel, matching tool terminal, or true terminal progress.
            $status = SubagentLiveStatusEnum::WaitingHuman;
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

    private function clearNeedsInputLatch(string $agentRunId): void
    {
        unset($this->needsInputLatchesByRunId[$agentRunId]);
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
