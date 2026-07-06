<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\FileRewind\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\HatfieldExt\FileRewind\GitProcessRunner;
use Ineersa\HatfieldExt\FileRewind\HiddenGitSnapshotBackend;
use Ineersa\HatfieldExt\FileRewind\RewindPathScope;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class HiddenGitProjectGitUntouchedTest extends TestCase
{
    private string $projectDir;
    private GitProcessRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new GitProcessRunner();
        if (!$this->runner->isGitAvailable()) {
            $this->markTestSkipped('git not available');
        }

        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('file-rewind-real-git');
        $this->initRealGitRepo($this->projectDir);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    public function testCaptureAndRestoreLeavesProjectGitStateUntouched(): void
    {
        $refBefore = $this->gitInProject(['rev-parse', 'refs/heads/main']);
        $headBefore = $this->gitInProject(['rev-parse', 'HEAD']);
        $stagedBefore = $this->gitInProject(['diff', '--cached', '--name-only']);
        $configBefore = (string) file_get_contents($this->projectDir.'/.git/config');

        $hiddenGit = $this->projectDir.'/.hatfield/rewind/snapshots/real/git';
        $backend = new HiddenGitSnapshotBackend($this->runner, new NullLogger());
        $scope = new RewindPathScope($this->projectDir);
        $idx = $this->projectDir.'/.hatfield/tmp/cap.index';
        @mkdir(\dirname($idx), 0700, true);

        $tree1 = $backend->captureTreeSha($hiddenGit, $this->projectDir, $idx, $scope);
        $commit1 = $backend->treeShaToCommitSha($hiddenGit, $this->projectDir, $tree1, 'checkpoint');

        file_put_contents($this->projectDir.'/tracked.txt', "mutated\n");
        file_put_contents($this->projectDir.'/new-only.txt', "x\n");

        $backend->restoreCommitToWorktree($hiddenGit, $this->projectDir, $commit1, $scope, $this->projectDir.'/.hatfield/tmp');

        $this->assertSame("staged-change\n", file_get_contents($this->projectDir.'/tracked.txt'));
        $this->assertFileDoesNotExist($this->projectDir.'/new-only.txt');

        $this->assertSame($refBefore, $this->gitInProject(['rev-parse', 'refs/heads/main']));
        $this->assertSame($headBefore, $this->gitInProject(['rev-parse', 'HEAD']));
        $this->assertSame($stagedBefore, $this->gitInProject(['diff', '--cached', '--name-only']));
        $this->assertSame($configBefore, (string) file_get_contents($this->projectDir.'/.git/config'));
    }

    /**
     * @param list<string> $args
     */
    private function gitInProject(array $args): string
    {
        $r = $this->runner->run($args, ['GIT_DIR' => $this->projectDir.'/.git', 'GIT_WORK_TREE' => $this->projectDir]);

        return trim($r->stdout);
    }

    private function initRealGitRepo(string $dir): void
    {
        $this->runner->run(['init', '-b', 'main'], ['GIT_DIR' => $dir.'/.git', 'GIT_WORK_TREE' => $dir]);
        $this->runner->run(['config', 'user.email', 'test@example.com'], ['GIT_DIR' => $dir.'/.git', 'GIT_WORK_TREE' => $dir]);
        $this->runner->run(['config', 'user.name', 'Test'], ['GIT_DIR' => $dir.'/.git', 'GIT_WORK_TREE' => $dir]);
        $this->runner->run(['config', 'commit.gpgsign', 'false'], ['GIT_DIR' => $dir.'/.git', 'GIT_WORK_TREE' => $dir]);
        file_put_contents($dir.'/tracked.txt', "tracked-v1\n");
        $this->runner->run(['add', 'tracked.txt'], ['GIT_DIR' => $dir.'/.git', 'GIT_WORK_TREE' => $dir]);
        $this->runner->run(['commit', '-m', 'initial'], ['GIT_DIR' => $dir.'/.git', 'GIT_WORK_TREE' => $dir]);
        file_put_contents($dir.'/tracked.txt', "staged-change\n");
        $this->runner->run(['add', 'tracked.txt'], ['GIT_DIR' => $dir.'/.git', 'GIT_WORK_TREE' => $dir]);
        $this->runner->run(['update-ref', 'refs/heads/feature-x', 'HEAD'], ['GIT_DIR' => $dir.'/.git', 'GIT_WORK_TREE' => $dir]);
    }
}
