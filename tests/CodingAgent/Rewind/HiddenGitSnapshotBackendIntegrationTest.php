<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Rewind;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Rewind\GitProcessRunner;
use Ineersa\CodingAgent\Rewind\HiddenGitSnapshotBackend;
use Ineersa\CodingAgent\Rewind\RewindPathScope;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class HiddenGitSnapshotBackendIntegrationTest extends TestCase
{
    private string $projectDir;
    private string $hiddenGit;

    protected function setUp(): void
    {
        $runner = new GitProcessRunner();
        if (!$runner->isGitAvailable()) {
            $this->markTestSkipped('git not available');
        }

        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('rewind-git');
        mkdir($this->projectDir.'/.git', 0700, true);
        file_put_contents($this->projectDir.'/tracked.txt', 'v1');
        file_put_contents($this->projectDir.'/untracked.txt', 'u1');
        $this->hiddenGit = $this->projectDir.'/.hatfield/rewind/snapshots/test/git';
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    public function testExactRestoreAndProjectGitUntouched(): void
    {
        $logger = new NullLogger();
        $backend = new HiddenGitSnapshotBackend(new GitProcessRunner(), $logger);
        $scope = new RewindPathScope($this->projectDir);
        $idx = $this->projectDir.'/.hatfield/tmp/cap.index';
        @mkdir(dirname($idx), 0700, true);

        $tree1 = $backend->captureTreeSha($this->hiddenGit, $this->projectDir, $idx, $scope);
        $commit1 = $backend->treeShaToCommitSha($this->hiddenGit, $this->projectDir, $tree1, 'c1');

        file_put_contents($this->projectDir.'/tracked.txt', 'v2');
        file_put_contents($this->projectDir.'/extra.txt', 'new');
        unlink($this->projectDir.'/untracked.txt');

        $idx2 = $this->projectDir.'/.hatfield/tmp/cap2.index';
        $backend->restoreCommitToWorktree($this->hiddenGit, $this->projectDir, $commit1, $scope);

        self::assertSame('v1', file_get_contents($this->projectDir.'/tracked.txt'));
        self::assertFileExists($this->projectDir.'/untracked.txt');
        self::assertFileDoesNotExist($this->projectDir.'/extra.txt');
        self::assertDirectoryExists($this->projectDir.'/.git');
    }
}
