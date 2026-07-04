<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\FileRewind;

final class FileRewindLedgerProjector
{
    /**
     * @param list<array<string, mixed>> $checkpoints
     *
     * @return array<int, FileRewindLedgerEntry>
     */
    public function checkpointsByTurn(array $checkpoints, int $maxRetainedTurns, ?string $runId = null): array
    {
        $records = [];
        foreach ($checkpoints as $row) {
            if (null !== $runId && (string) ($row['run_id'] ?? '') !== $runId) {
                continue;
            }
            $turnNo = (int) ($row['turn_no'] ?? 0);
            $commit = (string) ($row['snapshot_commit_sha'] ?? '');
            $kind = FileRewindCheckpointKindEnum::tryFrom((string) ($row['kind'] ?? ''));
            if ($turnNo <= 0 || '' === $commit || null === $kind) {
                continue;
            }
            if (FileRewindCheckpointKindEnum::RestoreUndo === $kind) {
                continue;
            }
            $records[$turnNo] = new FileRewindLedgerEntry(
                runId: (string) ($row['run_id'] ?? ''),
                turnNo: $turnNo,
                anchorSeq: (int) ($row['anchor_seq'] ?? 0),
                kind: $kind,
                snapshotCommitSha: $commit,
                projectHash: (string) ($row['project_hash'] ?? ''),
                pruned: false,
                unavailableReason: null,
            );
        }
        if ([] === $records) {
            return [];
        }
        ksort($records);
        $turnNos = array_keys($records);
        $keepTurns = \array_slice($turnNos, -$maxRetainedTurns);
        $keepSet = array_flip($keepTurns);
        $out = [];
        foreach ($records as $turnNo => $entry) {
            if (!isset($keepSet[$turnNo])) {
                $out[$turnNo] = new FileRewindLedgerEntry(
                    runId: $entry->runId,
                    turnNo: $entry->turnNo,
                    anchorSeq: $entry->anchorSeq,
                    kind: $entry->kind,
                    snapshotCommitSha: $entry->snapshotCommitSha,
                    projectHash: $entry->projectHash,
                    pruned: true,
                    unavailableReason: 'File checkpoint pruned by retention policy.',
                );
            } else {
                $out[$turnNo] = $entry;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $restores
     */
    public function findUndoCheckpoint(array $restores): ?FileRewindLedgerEntry
    {
        $undo = null;
        foreach ($restores as $row) {
            $undoSha = (string) ($row['undo_snapshot_commit_sha'] ?? '');
            if ('' === $undoSha) {
                continue;
            }
            $status = (string) ($row['status'] ?? 'succeeded');
            if ('succeeded' !== $status && 'failed' !== $status) {
                continue;
            }
            $undo = new FileRewindLedgerEntry(
                runId: (string) ($row['run_id'] ?? ''),
                turnNo: (int) ($row['turn_no'] ?? 0),
                anchorSeq: 0,
                kind: FileRewindCheckpointKindEnum::RestoreUndo,
                snapshotCommitSha: $undoSha,
                projectHash: (string) ($row['project_hash'] ?? ''),
                pruned: false,
                unavailableReason: null,
            );
        }

        return $undo;
    }
}
