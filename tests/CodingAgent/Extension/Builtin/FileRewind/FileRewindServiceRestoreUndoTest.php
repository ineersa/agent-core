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
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\RewindProjectIdentity;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\RewindStoragePaths;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Command\FileRewindActionEnum;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FileRewindServiceRestoreUndoTest extends TestCase
{
    private string $projectDir;
    private GitProcessRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new GitProcessRunner();
        if (!$this->runner->isGitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('file-rewind-restore-undo');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    public function testRestoreRecordsUndoMetadataAndUndoRevertsWorktree(): void
    {
        $runId = 'run-restore-undo';
        $service = $this->makeService();
        $identity = RewindProjectIdentity::fromProjectRoot($this->projectDir);
        $ledger = new FileRewindLedgerStore($this->projectDir);
        $backend = new HiddenGitSnapshotBackend($this->runner, new NullLogger());
        $paths = new RewindStoragePaths($this->projectDir);
        $gitDir = $paths->hiddenGitDir($identity);
        $scope = new \Ineersa\CodingAgent\Extension\Builtin\FileRewind\RewindPathScope($this->projectDir);
        $idx = $paths->tmpDir($identity).'/cap.index';
        @mkdir(dirname($idx), 0700, true);

        file_put_contents($this->projectDir.'/state.txt', "before\n");
        $tree1 = $backend->captureTreeSha($gitDir, $this->projectDir, $idx, $scope);
        $commit1 = $backend->treeShaToCommitSha($gitDir, $this->projectDir, $tree1, 'turn1');
        $ledger->appendCheckpoint($identity, [
            'run_id' => $runId,
            'turn_no' => 1,
            'anchor_seq' => 1,
            'kind' => FileRewindCheckpointKindEnum::TurnBoundary->value,
            'project_hash' => $identity->projectHash,
            'snapshot_commit_sha' => $commit1,
        ]);

        file_put_contents($this->projectDir.'/state.txt', "after\n");
        file_put_contents($this->projectDir.'/extra.txt', "x\n");

        $service->restoreForTurn($runId, 1);

        self::assertSame("before\n", file_get_contents($this->projectDir.'/state.txt'));
        self::assertFileDoesNotExist($this->projectDir.'/extra.txt');

        $restores = $ledger->readRestores($identity);
        self::assertCount(1, $restores);
        self::assertSame('succeeded', $restores[0]['status'] ?? '');
        self::assertNotSame('', (string) ($restores[0]['undo_snapshot_commit_sha'] ?? ''));

        $service->executeAction($runId, 0, FileRewindActionEnum::UndoLastRestore);

        self::assertSame("after\n", file_get_contents($this->projectDir.'/state.txt'));
        self::assertFileExists($this->projectDir.'/extra.txt');
    }

    public function testRestoreFailureSurfacesInLedger(): void
    {
        $runId = 'run-fail';
        $service = $this->makeService();
        $identity = RewindProjectIdentity::fromProjectRoot($this->projectDir);
        $ledger = new FileRewindLedgerStore($this->projectDir);
        $ledger->appendCheckpoint($identity, [
            'run_id' => $runId,
            'turn_no' => 9,
            'anchor_seq' => 1,
            'kind' => FileRewindCheckpointKindEnum::TurnBoundary->value,
            'project_hash' => $identity->projectHash,
            'snapshot_commit_sha' => 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef',
        ]);

        try {
            $service->restoreForTurn($runId, 9);
            self::fail('Expected restore to fail for invalid commit');
        } catch (\Throwable) {
        }

        $restores = $ledger->readRestores($identity);
        self::assertCount(1, $restores);
        self::assertSame('failed', $restores[0]['status'] ?? '');
        self::assertNotSame('', (string) ($restores[0]['error'] ?? ''));
    }

    private function makeService(): FileRewindService
    {
        $runner = $this->runner;
        $paths = new RewindStoragePaths($this->projectDir);
        $config = new FileRewindConfig(enabled: true, maxRetainedTurns: 10, maxFileBytes: 1_048_576);

        return new FileRewindService(
            backend: new HiddenGitSnapshotBackend($runner, new NullLogger()),
            gitRunner: $runner,
            paths: $paths,
            ledgerStore: new FileRewindLedgerStore($this->projectDir),
            ledgerProjector: new FileRewindLedgerProjector(),
            config: $config,
            logger: new NullLogger(),
            projectCwd: $this->projectDir,
        );
    }
}
