<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\FileRewind\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\HatfieldExt\FileRewind\GitProcessRunner;
use Ineersa\HatfieldExt\FileRewind\HiddenGitSnapshotBackend;
use Ineersa\HatfieldExt\FileRewind\RewindPathScope;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class HiddenGitRewindNotTrackedByProjectGitTest extends TestCase
{
    private string $projectDir;
    private GitProcessRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new GitProcessRunner();
        if (!$this->runner->isGitAvailable()) {
            $this->markTestSkipped('git not available');
        }

        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('file-rewind-not-tracked');
        $this->initRealGitRepo($this->projectDir);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    public function testHiddenSnapshotDirDoesNotAppearInProjectGitStatus(): void
    {
        $hiddenGit = $this->projectDir.'/.hatfield/rewind/snapshots/real/git';
        $backend = new HiddenGitSnapshotBackend($this->runner, new NullLogger());
        $scope = new RewindPathScope($this->projectDir);
        $idx = $this->projectDir.'/.hatfield/tmp/cap.index';
        @mkdir(\dirname($idx), 0700, true);

        $tree1 = $backend->captureTreeSha($hiddenGit, $this->projectDir, $idx, $scope);
        $backend->treeShaToCommitSha($hiddenGit, $this->projectDir, $tree1, 'checkpoint');

        $this->assertDirectoryExists($hiddenGit);

        $porcelain = $this->gitInProject(['status', '--porcelain']);
        $this->assertStringNotContainsString('.hatfield/rewind', $porcelain);
        $this->assertStringNotContainsString('rewind/snapshots', $porcelain);
    }

    private function initRealGitRepo(string $dir): void
    {
        $env = ['GIT_DIR' => $dir.'/.git', 'GIT_WORK_TREE' => $dir];
        $this->runner->run(['init', '-b', 'main'], $env);
        $this->runner->run(['config', 'user.email', 'test@example.com'], $env);
        $this->runner->run(['config', 'user.name', 'Test'], $env);
        $this->runner->run(['config', 'commit.gpgsign', 'false'], $env);
        file_put_contents($dir.'/tracked.txt', "tracked-v1\n");
        $this->runner->run(['add', 'tracked.txt'], $env);
        $this->runner->run(['commit', '-m', 'initial'], $env);
    }

    /** @param list<string> $args */
    private function gitInProject(array $args): string
    {
        $r = $this->runner->run($args, ['GIT_DIR' => $this->projectDir.'/.git', 'GIT_WORK_TREE' => $this->projectDir]);

        return trim($r->stdout);
    }
}
