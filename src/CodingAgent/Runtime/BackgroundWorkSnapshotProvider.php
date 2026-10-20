<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime;

use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Entity\BackgroundProcessRepository;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Runtime\Contract\DTO\BackgroundChildStatusDTO;
use Ineersa\CodingAgent\Runtime\Contract\DTO\BackgroundWorkDTO;
use Ineersa\CodingAgent\Runtime\Contract\DTO\BackgroundWorkSnapshotDTO;

/** Read-only presentation over existing native ownership and operational rows. */
final readonly class BackgroundWorkSnapshotProvider
{
    public function __construct(private DeferredSubagentBatchRepository $batches, private BackgroundProcessRepository $processes)
    {
    }

    public function snapshot(string $rootRunId, ?int $observedProcessId = null): BackgroundWorkSnapshotDTO
    {
        $children = [];
        $owners = [];
        $invocations = [];
        foreach ($this->batches->findOwnedChildStatuses($rootRunId) as $row) {
            $child = new BackgroundChildStatusDTO(
                (string) $row['lifecycle_id'],
                (string) $row['child_run_id'], (string) $row['artifact_id'], (string) $row['agent_name'],
                (string) $row['task'], (string) $row['launch_model'], (string) $row['launch_reasoning'],
                (string) $row['status'], null !== $row['backgrounded_at'],
            );
            $children[] = $child;
            $owners[$child->runId] = $this->ownerLabel($child);
            if ($child->backgrounded) {
                $invocations[$child->lifecycleId][] = $child;
            }
        }
        $work = [];
        foreach ($invocations as $id => $members) {
            $active = array_values(array_filter($members, static fn (BackgroundChildStatusDTO $child): bool => !RunStatus::from($child->status)->isTerminal()));
            $statuses = array_unique(array_map(static fn (BackgroundChildStatusDTO $child): string => $child->status, [] !== $active ? $active : $members));
            $status = implode('/', $statuses);
            $work[] = new BackgroundWorkDTO(
                $id, 'agent', implode('; ', array_map($this->ownerLabel(...), $members)), $status,
                [] === $active, array_map(static fn (BackgroundChildStatusDTO $child): string => $child->runId, $active),
            );
        }
        foreach ($this->processes->findOwnedBackgroundWork($rootRunId, $observedProcessId) as $row) {
            $runId = (string) $row['session_id'];
            $terminal = null !== $row['finished_at'];
            $status = !$terminal && 'stopped' === $row['status'] ? 'stopping' : (string) $row['status'];
            $owner = $runId === $rootRunId ? 'main run:'.$rootRunId : $owners[$runId];
            $work[] = new BackgroundWorkDTO(
                (string) $row['id'], 'bash', \sprintf('PID %d | %s | %s', $row['pid'], $owner, $row['command']),
                $status, $terminal, [$runId], (int) $row['id'], new \DateTimeImmutable((string) $row['started_at']),
            );
        }

        return new BackgroundWorkSnapshotDTO($children, $work);
    }

    private function ownerLabel(BackgroundChildStatusDTO $child): string
    {
        return \sprintf('%s agent_id:%s run:%s', $child->name, $child->artifactId, $child->runId);
    }
}
