<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Rewind;

use Ineersa\CodingAgent\Rewind\GitProcessRunner;
use Ineersa\CodingAgent\Rewind\HiddenGitSnapshotBackend;
use Ineersa\CodingAgent\Rewind\RewindPathScope;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class HiddenGitSymlinkTraversalTest extends TestCase
{
    private string $projectDir;
    private GitProcessRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new GitProcessRunner();
        if (!$this->runner->isGitAvailable()) {
            $this->markTestSkipped('git not available');
        }

        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('rewind-symlink');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    public function testSymlinkedDirectoryOutsideProjectIsNotStaged(): void
    {
        $outside = TestDirectoryIsolation::createOsTempDir('rewind-outside');
        file_put_contents($outside.'/secret.txt', 'outside-secret');

        symlink($outside, $this->projectDir.'/outside-link');
        file_put_contents($this->projectDir.'/in-project.txt', 'inside');

        $hiddenGit = $this->projectDir.'/.hatfield/rewind/snapshots/sym/git';
        $backend = new HiddenGitSnapshotBackend($this->runner, new NullLogger(), 2_097_152);
        $scope = new RewindPathScope($this->projectDir);
        $idx = $this->projectDir.'/.hatfield/tmp/cap.index';
        @mkdir(dirname($idx), 0700, true);

        $tree = $backend->captureTreeSha($hiddenGit, $this->projectDir, $idx, $scope);
        $paths = $backend->listTreePaths($hiddenGit, $this->projectDir, $tree);

        self::assertContains('in-project.txt', $paths);
        self::assertFalse(
            (bool) array_filter($paths, static fn (string $p): bool => str_contains($p, 'secret.txt')),
            'paths: '.implode(', ', $paths),
        );

        TestDirectoryIsolation::removeDirectory($outside);
    }
}
