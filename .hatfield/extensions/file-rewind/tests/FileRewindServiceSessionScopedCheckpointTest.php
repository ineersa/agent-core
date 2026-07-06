<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\FileRewind\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\HatfieldExt\FileRewind\FileRewindCheckpointKindEnum;
use Ineersa\HatfieldExt\FileRewind\FileRewindConfig;
use Ineersa\HatfieldExt\FileRewind\FileRewindLedgerProjector;
use Ineersa\HatfieldExt\FileRewind\FileRewindLedgerStore;
use Ineersa\HatfieldExt\FileRewind\FileRewindService;
use Ineersa\HatfieldExt\FileRewind\GitProcessRunner;
use Ineersa\HatfieldExt\FileRewind\HiddenGitSnapshotBackend;
use Ineersa\HatfieldExt\FileRewind\RewindPathScope;
use Ineersa\HatfieldExt\FileRewind\RewindProjectIdentity;
use Ineersa\HatfieldExt\FileRewind\RewindStoragePaths;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FileRewindServiceSessionScopedCheckpointTest extends TestCase
{
    private string $projectDir;
    private GitProcessRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new GitProcessRunner();
        if (!$this->runner->isGitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('file-rewind-session-scope');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    public function testRestoreUsesCheckpointFromActiveSessionNotOlderRunOnSameTurnNo(): void
    {
        $service = $this->makeService();
        $identity = RewindProjectIdentity::fromProjectRoot($this->projectDir);
        $ledger = new FileRewindLedgerStore($this->projectDir);
        $backend = new HiddenGitSnapshotBackend($this->runner, new NullLogger());
        $paths = new RewindStoragePaths($this->projectDir);
        $gitDir = $paths->hiddenGitDir($identity);
        $scope = new RewindPathScope($this->projectDir);
        $idx = $paths->tmpDir($identity).'/cap.index';
        @mkdir(\dirname($idx), 0700, true);

        $treeEmpty = $backend->captureTreeSha($gitDir, $this->projectDir, $idx, $scope);
        $commitEmpty = $backend->treeShaToCommitSha($gitDir, $this->projectDir, $treeEmpty, 'old-session-empty');
        $ledger->appendCheckpoint($identity, [
            'run_id' => 'old-session',
            'turn_no' => 1,
            'anchor_seq' => 1,
            'kind' => FileRewindCheckpointKindEnum::TurnBoundary->value,
            'project_hash' => $identity->projectHash,
            'snapshot_commit_sha' => $commitEmpty,
        ]);

        file_put_contents($this->projectDir.'/test.txt', "LINE_ONE\n");
        $treeOneLine = $backend->captureTreeSha($gitDir, $this->projectDir, $idx, $scope);
        $commitOneLine = $backend->treeShaToCommitSha($gitDir, $this->projectDir, $treeOneLine, 'active-one-line');
        $ledger->appendCheckpoint($identity, [
            'run_id' => 'active-session',
            'turn_no' => 1,
            'anchor_seq' => 1,
            'kind' => FileRewindCheckpointKindEnum::TurnBoundary->value,
            'project_hash' => $identity->projectHash,
            'snapshot_commit_sha' => $commitOneLine,
        ]);

        file_put_contents($this->projectDir.'/test.txt', "LINE_ONE\nLINE_TWO\n");

        $this->assertTrue($service->hasCheckpointForTurn('active-session', 1));
        $this->assertFalse($service->hasCheckpointForTurn('active-session', 2));

        $service->restoreForTurn('active-session', 1);

        $this->assertSame("LINE_ONE\n", file_get_contents($this->projectDir.'/test.txt'));
        $this->assertFileExists($this->projectDir.'/test.txt');
    }

    private function makeService(): FileRewindService
    {
        $config = new FileRewindConfig(enabled: true, maxRetainedTurns: 100, maxFileBytes: 2_097_152, gitTimeoutSeconds: 30);

        return new FileRewindService(
            new HiddenGitSnapshotBackend($this->runner, new NullLogger()),
            $this->runner,
            new RewindStoragePaths($this->projectDir),
            new FileRewindLedgerStore($this->projectDir),
            new FileRewindLedgerProjector(),
            $config,
            new NullLogger(),
            $this->projectDir,
        );
    }
}
