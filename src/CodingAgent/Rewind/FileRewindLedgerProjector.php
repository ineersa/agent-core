<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Rewind;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;

/**
 * Rebuilds file rewind checkpoint index from append-only canonical events.
 */
final class FileRewindLedgerProjector
{
    public const string EVENT_CHECKPOINT = 'file_rewind.checkpoint_recorded';
    public const string EVENT_RESTORED = 'file_rewind.restored';

    /** @var list<string> */
    private const RETAINED_KINDS = [
        FileRewindCheckpointKindEnum::UserBoundary->value,
        FileRewindCheckpointKindEnum::AssistantBoundary->value,
    ];

    /**
     * @param list<RunEvent> $events
     *
     * @return array<int, FileRewindLedgerEntry> turnNo => best checkpoint for restore
     */
    public function checkpointsByTurn(array $events, int $maxRetainedTurns): array
    {
        $records = [];
        $latestUndo = null;

        foreach ($events as $event) {
            if (RunEventTypeEnum::FileRewindCheckpointRecorded->value === $event->type) {
                $p = $event->payload;
                $kind = FileRewindCheckpointKindEnum::tryFrom((string) ($p['kind'] ?? ''));
                $commit = (string) ($p['snapshot_commit_sha'] ?? '');
                if (null === $kind || '' === $commit) {
                    continue;
                }
                $entry = new FileRewindLedgerEntry(
                    turnNo: (int) ($p['turn_no'] ?? $event->turnNo),
                    eventSeq: $event->seq,
                    kind: $kind,
                    snapshotCommitSha: $commit,
                    projectHash: (string) ($p['project_hash'] ?? ''),
                    pruned: false,
                    unavailableReason: null,
                );
                if (FileRewindCheckpointKindEnum::RestoreUndo === $kind) {
                    $latestUndo = $entry;
                    continue;
                }
                if (\in_array($kind->value, self::RETAINED_KINDS, true)) {
                    $records[$entry->turnNo] = $entry;
                }
            }
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
                    turnNo: $entry->turnNo,
                    eventSeq: $entry->eventSeq,
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
     * @param list<RunEvent> $events
     */
    public function findUndoCheckpoint(array $events): ?FileRewindLedgerEntry
    {
        $undo = null;
        foreach ($events as $event) {
            if (RunEventTypeEnum::FileRewindRestored->value !== $event->type) {
                continue;
            }
            $undoSha = (string) ($event->payload['undo_snapshot_commit_sha'] ?? '');
            if ('' === $undoSha) {
                continue;
            }
            $undo = new FileRewindLedgerEntry(
                turnNo: (int) ($event->payload['turn_no'] ?? $event->turnNo),
                eventSeq: $event->seq,
                kind: FileRewindCheckpointKindEnum::RestoreUndo,
                snapshotCommitSha: $undoSha,
                projectHash: (string) ($event->payload['project_hash'] ?? ''),
                pruned: false,
                unavailableReason: null,
            );
        }

        return $undo;
    }
}
