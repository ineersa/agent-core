<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime;

use DateTimeImmutable as ImmutableDateTime;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Entity\BackgroundProcessRepository;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Runtime\Contract\DTO\BackgroundChildStatusDTO;
use Ineersa\CodingAgent\Runtime\Contract\DTO\BackgroundWorkDTO;
use Ineersa\CodingAgent\Runtime\Contract\DTO\BackgroundWorkSnapshotDTO;

/** Read-only presentation over existing native ownership and operational rows. */
final readonly class BackgroundWorkSnapshotProvider
{
    public function __construct(private DeferredSubagentBatchRepository $batches, private BackgroundProcessRepository $processes, private CodeModeExecutionRepository $codeMode)
    {
    }

    public function snapshot(string $rootRunId, ?int $observedProcessId = null, ?string $observedCodeModeIdentity = null): BackgroundWorkSnapshotDTO
    {
        $children = [];
        $invocations = [];
        foreach ($this->batches->findOwnedChildStatuses($rootRunId) as $row) {
            $child = new BackgroundChildStatusDTO(
                (string) $row['lifecycle_id'],
                (string) $row['child_run_id'], (string) $row['artifact_id'], (string) $row['agent_name'],
                (string) $row['task'], (string) $row['launch_model'], (string) $row['launch_reasoning'],
                (string) $row['status'], null !== $row['backgrounded_at'],
            );
            $children[] = $child;
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
            $owner = $runId === $rootRunId ? 'main run:'.$rootRunId : \sprintf('%s agent_id:%s run:%s', $row['owner_name'], $row['owner_artifact_id'], $runId);
            $work[] = new BackgroundWorkDTO(
                (string) $row['id'], 'bash', \sprintf('PID %d | %s | %s', $row['pid'], $owner, $row['command']),
                $status, $terminal, [$runId], (int) $row['id'], new ImmutableDateTime((string) $row['started_at']),
            );
        }

        foreach ($this->codeMode->ownedBackgroundWork($rootRunId, $observedCodeModeIdentity) as $row) {
            $terminal = null !== $row['raw_reference'] || null !== $row['accepted_result_reference_json'] || 'completed' === $row['deferred_status'];
            $interrupted = null !== $row['interruption_reason'];
            $status = $terminal ? ($interrupted ? 'interrupted' : 'finished') : ($interrupted ? 'stopping' : 'running');
            $runId = (string) $row['run_id'];
            $owner = $runId === $rootRunId ? 'main run:'.$rootRunId : \sprintf('%s agent_id:%s run:%s', $row['owner_name'], $row['owner_artifact_id'], $runId);
            $work[] = new BackgroundWorkDTO((string) $row['identity'], 'code_mode', \sprintf('code_mode %s | %s', $row['tool_call_id'], $owner), $status, $terminal, [$runId], toolCallId: (string) $row['tool_call_id']);
        }

        return new BackgroundWorkSnapshotDTO($children, $work);
    }

    private function ownerLabel(BackgroundChildStatusDTO $child): string
    {
        return \sprintf('%s agent_id:%s run:%s', $child->name, $child->artifactId, $child->runId);
    }
}
