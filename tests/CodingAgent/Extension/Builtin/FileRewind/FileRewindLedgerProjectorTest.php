<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\FileRewind;

use Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindCheckpointKindEnum;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindLedgerProjector;
use PHPUnit\Framework\TestCase;

final class FileRewindLedgerProjectorTest extends TestCase
{
    public function testRetentionMarksOlderTurnsPruned(): void
    {
        $projector = new FileRewindLedgerProjector();
        $rows = [];
        for ($i = 1; $i <= 3; ++$i) {
            $rows[] = [
                'run_id' => 'r1',
                'turn_no' => $i,
                'anchor_seq' => $i,
                'kind' => FileRewindCheckpointKindEnum::TurnBoundary->value,
                'snapshot_commit_sha' => str_repeat((string) $i, 40),
                'project_hash' => 'h',
            ];
        }
        $byTurn = $projector->checkpointsByTurn($rows, 2);
        self::assertTrue($byTurn[1]->pruned);
        self::assertFalse($byTurn[3]->pruned);
    }

    public function testCheckpointsByTurnFiltersByRunId(): void
    {
        $projector = new FileRewindLedgerProjector();
        $rows = [
            [
                'run_id' => 'session-a',
                'turn_no' => 1,
                'anchor_seq' => 1,
                'kind' => FileRewindCheckpointKindEnum::TurnBoundary->value,
                'snapshot_commit_sha' => str_repeat('a', 40),
                'project_hash' => 'h',
            ],
            [
                'run_id' => 'session-b',
                'turn_no' => 1,
                'anchor_seq' => 1,
                'kind' => FileRewindCheckpointKindEnum::TurnBoundary->value,
                'snapshot_commit_sha' => str_repeat('b', 40),
                'project_hash' => 'h',
            ],
        ];
        $byTurn = $projector->checkpointsByTurn($rows, 100, 'session-b');
        self::assertCount(1, $byTurn);
        self::assertSame('session-b', $byTurn[1]->runId);
        self::assertSame(str_repeat('b', 40), $byTurn[1]->snapshotCommitSha);
    }
}
