<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Rewind;

use Ineersa\CodingAgent\Rewind\GitProcessRunner;
use Ineersa\CodingAgent\Rewind\HiddenGitSnapshotBackend;
use Ineersa\CodingAgent\Rewind\RewindPathScope;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class HiddenGitPerCommitRefRetentionTest extends TestCase
{
    private string $projectDir;
    private string $hiddenGit;
    private HiddenGitSnapshotBackend $backend;
    private GitProcessRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new GitProcessRunner();
        if (!$this->runner->isGitAvailable()) {
            $this->markTestSkipped('git not available');
        }

        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('rewind-refs');
        file_put_contents($this->projectDir.'/a.txt', 'v1');
        $this->hiddenGit = $this->projectDir.'/.hatfield/rewind/snapshots/test/git';
        $this->backend = new HiddenGitSnapshotBackend($this->runner, new NullLogger());
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    public function testTwoCheckpointCommitsRemainReachableAfterThirdCapture(): void
    {
        $scope = new RewindPathScope($this->projectDir);
        $idx = $this->projectDir.'/.hatfield/tmp/cap.index';
        @mkdir(dirname($idx), 0700, true);

        $tree1 = $this->backend->captureTreeSha($this->hiddenGit, $this->projectDir, $idx, $scope);
        $commit1 = $this->backend->treeShaToCommitSha($this->hiddenGit, $this->projectDir, $tree1, 'c1');

        file_put_contents($this->projectDir.'/a.txt', 'v2');
        $tree2 = $this->backend->captureTreeSha($this->hiddenGit, $this->projectDir, $idx, $scope);
        $commit2 = $this->backend->treeShaToCommitSha($this->hiddenGit, $this->projectDir, $tree2, 'c2');

        file_put_contents($this->projectDir.'/b.txt', 'new');
        $tree3 = $this->backend->captureTreeSha($this->hiddenGit, $this->projectDir, $idx, $scope);
        $commit3 = $this->backend->treeShaToCommitSha($this->hiddenGit, $this->projectDir, $tree3, 'c3');

        self::assertTrue($this->backend->commitShaReachable($this->hiddenGit, $this->projectDir, $commit1));
        self::assertTrue($this->backend->commitShaReachable($this->hiddenGit, $this->projectDir, $commit2));
        self::assertTrue($this->backend->commitShaReachable($this->hiddenGit, $this->projectDir, $commit3));
        self::assertNotSame($commit1, $commit2);
    }

    public function testPruneRemovesRefsOutsideKeepSet(): void
    {
        $scope = new RewindPathScope($this->projectDir);
        $idx = $this->projectDir.'/.hatfield/tmp/cap.index';
        @mkdir(dirname($idx), 0700, true);

        $tree1 = $this->backend->captureTreeSha($this->hiddenGit, $this->projectDir, $idx, $scope);
        $commit1 = $this->backend->treeShaToCommitSha($this->hiddenGit, $this->projectDir, $tree1, 'c1');
        file_put_contents($this->projectDir.'/a.txt', 'v2');
        $tree2 = $this->backend->captureTreeSha($this->hiddenGit, $this->projectDir, $idx, $scope);
        $commit2 = $this->backend->treeShaToCommitSha($this->hiddenGit, $this->projectDir, $tree2, 'c2');

        $this->backend->pruneCommitRefs($this->hiddenGit, $this->projectDir, [$commit2]);

        self::assertFalse($this->backend->commitShaReachable($this->hiddenGit, $this->projectDir, $commit1));
        self::assertTrue($this->backend->commitShaReachable($this->hiddenGit, $this->projectDir, $commit2));
    }
}
