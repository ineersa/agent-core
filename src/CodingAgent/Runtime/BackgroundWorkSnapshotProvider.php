<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime;

use DateTimeImmutable as ImmutableDateTime;
use Ineersa\CodingAgent\Entity\BackgroundProcessRepository;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Entity\DeferredToolCompletionRepository;
use Ineersa\CodingAgent\Runtime\Contract\DTO\BackgroundChildStatusDTO;
use Ineersa\CodingAgent\Runtime\Contract\DTO\BackgroundWorkDTO;
use Ineersa\CodingAgent\Runtime\Contract\DTO\BackgroundWorkSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\DTO\ChildBackgroundCandidateDTO;

/** Read-only presentation over existing native ownership and operational rows. */
final readonly class BackgroundWorkSnapshotProvider
{
    public function __construct(private DeferredSubagentBatchRepository $batches, private BackgroundProcessRepository $processes, private DeferredToolCompletionRepository $deferred)
    {
    }

    /** @param list<string> $observedChildRunIds */
    public function snapshot(string $rootRunId, ?int $observedProcessId = null, array $observedChildRunIds = [], bool $includeChildHistory = false): BackgroundWorkSnapshotDTO
    {
        $children = [];
        // Active rows discover new/resumed work. Exact observed identities get
        // their final terminal status; historical inventory is on demand only.
        foreach ($this->batches->findOwnedChildStatuses($rootRunId, $observedChildRunIds, $includeChildHistory) as $row) {
            $child = new BackgroundChildStatusDTO(
                (string) $row['child_run_id'], (string) $row['artifact_id'], (string) $row['agent_name'],
                (string) $row['task'], (string) $row['model'], (string) $row['reasoning'],
                (string) $row['status'], null !== $row['backgrounded_at'],
                (int) $row['latest_input_tokens'], (int) $row['context_window'],
            );
            $children[] = $child;
        }
        $work = [];
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

        $candidates = [];
        foreach ($this->deferred->pendingOwnedCodeMode($rootRunId) as $row) {
            $id = (string) $row['deferred_id'];
            $runId = (string) $row['run_id'];
            $call = (string) $row['tool_call_id'];
            $label = "code_mode $call | run:$runId | invocation:$id";
            if (null !== $row['backgrounded_at']) {
                $work[] = new BackgroundWorkDTO($id, 'code_mode', $label, null === $row['interruption_reason'] ? 'running' : 'stopping', false, [$runId], startedAt: new ImmutableDateTime((string) $row['created_at']), toolCallId: $call);
            } elseif ($runId === $rootRunId && null !== $row['ready_at'] && null === $row['interruption_reason']) {
                $candidates[] = new ChildBackgroundCandidateDTO($id, $call, $label);
            }
        }

        return new BackgroundWorkSnapshotDTO($children, $work, $candidates);
    }
}
