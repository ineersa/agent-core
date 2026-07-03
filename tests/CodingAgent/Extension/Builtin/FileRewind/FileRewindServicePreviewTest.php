<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\FileRewind;

use Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindCheckpointKindEnum;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindConfig;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindLedgerProjector;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindLedgerStore;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindService;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\GitProcessRunner;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\HiddenGitSnapshotBackend;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\RewindPathScope;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\RewindProjectIdentity;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\RewindStoragePaths;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FileRewindServicePreviewTest extends TestCase
{
    private string $projectDir;
    private GitProcessRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new GitProcessRunner();
        if (!$this->runner->isGitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('file-rewind-preview');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    public function testPreviewReportsLineCountsForTextFileChanges(): void
    {
        $runId = 'run-preview';
        $identity = RewindProjectIdentity::fromProjectRoot($this->projectDir);
        $paths = new RewindStoragePaths($this->projectDir);
        $backend = new HiddenGitSnapshotBackend($this->runner, new NullLogger());
        $scope = new RewindPathScope($this->projectDir);
        $gitDir = $paths->hiddenGitDir($identity);
        $idx = $paths->tmpDir($identity).'/cap.index';
        @mkdir(dirname($idx), 0700, true);

        file_put_contents($this->projectDir.'/doc.txt', "line1\nline2\n");
        $tree1 = $backend->captureTreeSha($gitDir, $this->projectDir, $idx, $scope);
        $commit1 = $backend->treeShaToCommitSha($gitDir, $this->projectDir, $tree1, 't1');
        (new FileRewindLedgerStore($this->projectDir))->appendCheckpoint($identity, [
            'run_id' => $runId,
            'turn_no' => 1,
            'anchor_seq' => 1,
            'kind' => FileRewindCheckpointKindEnum::TurnBoundary->value,
            'project_hash' => $identity->projectHash,
            'snapshot_commit_sha' => $commit1,
        ]);

        file_put_contents($this->projectDir.'/doc.txt', "line1\nline2\nline3\n");
        file_put_contents($this->projectDir.'/new.txt', "n\n");

        $service = new FileRewindService(
            backend: $backend,
            gitRunner: $this->runner,
            paths: $paths,
            ledgerStore: new FileRewindLedgerStore($this->projectDir),
            ledgerProjector: new FileRewindLedgerProjector(),
            config: new FileRewindConfig(enabled: true, maxRetainedTurns: 10, maxFileBytes: 1_048_576),
            logger: new NullLogger(),
            projectCwd: $this->projectDir,
        );

        $rows = $service->previewForTurn($runId, 1);
        $byPath = [];
        foreach ($rows as $row) {
            $byPath[$row->path] = $row;
        }

        self::assertArrayHasKey('doc.txt', $byPath);
        self::assertSame('modified', $byPath['doc.txt']->status);
        self::assertGreaterThanOrEqual(1, $byPath['doc.txt']->removedLines, 'Restoring to turn 1 removes lines present only in current worktree');
        self::assertArrayHasKey('new.txt', $byPath);
        self::assertSame('deleted', $byPath['new.txt']->status);
    }

    public function testPreviewMarksTooLargeWhenLineCountExceedsLcsCap(): void
    {
        $runId = 'run-lines';
        $identity = RewindProjectIdentity::fromProjectRoot($this->projectDir);
        $paths = new RewindStoragePaths($this->projectDir);
        $backend = new HiddenGitSnapshotBackend($this->runner, new NullLogger());
        $scope = new RewindPathScope($this->projectDir);
        $gitDir = $paths->hiddenGitDir($identity);
        $idx = $paths->tmpDir($identity).'/cap2.index';
        @mkdir(dirname($idx), 0700, true);

        file_put_contents($this->projectDir.'/many.txt', implode("\n", array_fill(0, 9000, 'x')));
        $tree1 = $backend->captureTreeSha($gitDir, $this->projectDir, $idx, $scope);
        $commit1 = $backend->treeShaToCommitSha($gitDir, $this->projectDir, $tree1, 't1');
        (new FileRewindLedgerStore($this->projectDir))->appendCheckpoint($identity, [
            'run_id' => $runId,
            'turn_no' => 1,
            'anchor_seq' => 1,
            'kind' => FileRewindCheckpointKindEnum::TurnBoundary->value,
            'project_hash' => $identity->projectHash,
            'snapshot_commit_sha' => $commit1,
        ]);

        file_put_contents($this->projectDir.'/many.txt', implode("\n", array_fill(0, 9001, 'y')));

        $service = new FileRewindService(
            backend: $backend,
            gitRunner: $this->runner,
            paths: $paths,
            ledgerStore: new FileRewindLedgerStore($this->projectDir),
            ledgerProjector: new FileRewindLedgerProjector(),
            config: new FileRewindConfig(enabled: true, maxRetainedTurns: 10, maxFileBytes: 50_000_000),
            logger: new NullLogger(),
            projectCwd: $this->projectDir,
        );

        $rows = $service->previewForTurn($runId, 1);
        self::assertNotEmpty($rows);
        self::assertTrue($rows[0]->tooLarge);
        self::assertSame(0, $rows[0]->addedLines);
        self::assertSame(0, $rows[0]->removedLines);
    }

    public function testRepeatedPreviewForTurnReturnsConsistentRows(): void
    {
        $runId = 'run-repeat';
        $identity = RewindProjectIdentity::fromProjectRoot($this->projectDir);
        $paths = new RewindStoragePaths($this->projectDir);
        $backend = new HiddenGitSnapshotBackend($this->runner, new NullLogger());
        $scope = new RewindPathScope($this->projectDir);
        $gitDir = $paths->hiddenGitDir($identity);
        $idx = $paths->tmpDir($identity).'/cap3.index';
        @mkdir(dirname($idx), 0700, true);

        file_put_contents($this->projectDir.'/a.txt', "one
");
        $tree1 = $backend->captureTreeSha($gitDir, $this->projectDir, $idx, $scope);
        $commit1 = $backend->treeShaToCommitSha($gitDir, $this->projectDir, $tree1, 't1');
        (new FileRewindLedgerStore($this->projectDir))->appendCheckpoint($identity, [
            'run_id' => $runId,
            'turn_no' => 1,
            'anchor_seq' => 1,
            'kind' => FileRewindCheckpointKindEnum::TurnBoundary->value,
            'project_hash' => $identity->projectHash,
            'snapshot_commit_sha' => $commit1,
        ]);
        file_put_contents($this->projectDir.'/a.txt', "one
two
");

        $service = new FileRewindService(
            backend: $backend,
            gitRunner: $this->runner,
            paths: $paths,
            ledgerStore: new FileRewindLedgerStore($this->projectDir),
            ledgerProjector: new FileRewindLedgerProjector(),
            config: new FileRewindConfig(enabled: true, maxRetainedTurns: 10, maxFileBytes: 1_048_576),
            logger: new NullLogger(),
            projectCwd: $this->projectDir,
        );

        $first = $service->previewForTurn($runId, 1);
        $second = $service->previewForTurn($runId, 1);
        self::assertSame(count($first), count($second));
        if ([] !== $first) {
            self::assertSame($first[0]->path, $second[0]->path);
            self::assertSame($first[0]->status, $second[0]->status);
        }
    }


}

