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

    /**
     * @return list<SubagentLiveChildDTO>
     */
    public function all(): array
    {
        $items = array_values($this->byArtifactId);
        usort($items, static fn (SubagentLiveChildDTO $a, SubagentLiveChildDTO $b): int => $b->lastActivityAtMs <=> $a->lastActivityAtMs);

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
        if ('' === $artifactId) {
            return;
        }

        $agentRunId = trim((string) ($row['agent_run_id'] ?? ''));
        $agentName = trim((string) ($row['agent_name'] ?? 'subagent'));
        $status = SubagentLiveStatusEnum::fromProgressString((string) ($row['status'] ?? 'running'));
        $taskSummary = trim((string) ($row['task_summary'] ?? ''));

        if ('' === $agentRunId) {
            $existing = $this->byArtifactId[$artifactId] ?? null;
            $agentRunId = null !== $existing ? $existing->agentRunId : '';
        }

        if ('' === $agentRunId) {
            return;
        }

        $this->byArtifactId[$artifactId] = new SubagentLiveChildDTO(
            agentRunId: $agentRunId,
            artifactId: $artifactId,
            agentName: $agentName,
            status: $status,
            taskSummary: $taskSummary,
            lastActivityAtMs: $now,
        );
    }
}
