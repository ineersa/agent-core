<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Rewind;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Rewind\FileRewindCheckpointKindEnum;
use Ineersa\CodingAgent\Rewind\FileRewindLedgerProjector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileRewindLedgerProjector::class)]
final class FileRewindLedgerProjectorTest extends TestCase
{
    public function testRetentionMarksOldTurnPruned(): void
    {
        $events = [];
        for ($turn = 1; $turn <= 3; ++$turn) {
            $events[] = new RunEvent(
                runId: 'r1',
                seq: $turn,
                turnNo: $turn,
                type: RunEventTypeEnum::FileRewindCheckpointRecorded->value,
                payload: [
                    'turn_no' => $turn,
                    'kind' => FileRewindCheckpointKindEnum::AssistantBoundary->value,
                    'snapshot_commit_sha' => 'sha'.$turn,
                    'project_hash' => 'ph',
                ],
                createdAt: new \DateTimeImmutable(),
            );
        }

        $projector = new FileRewindLedgerProjector();
        $map = $projector->checkpointsByTurn($events, 2);

        self::assertTrue($map[1]->pruned);
        self::assertFalse($map[3]->pruned);
    }

    public function testFindUndoFromRestoreEvent(): void
    {
        $events = [
            new RunEvent('r1', 1, 2, RunEventTypeEnum::FileRewindRestored->value, [
                'turn_no' => 2,
                'undo_snapshot_commit_sha' => 'undo-sha',
                'project_hash' => 'ph',
            ], new \DateTimeImmutable()),
        ];

        $undo = (new FileRewindLedgerProjector())->findUndoCheckpoint($events);
        self::assertNotNull($undo);
        self::assertSame('undo-sha', $undo->snapshotCommitSha);
    }
}
