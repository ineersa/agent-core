<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Librarian\LibrarianProjectPolicy;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class LibrarianProjectPolicyTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = TestDirectoryIsolation::createOsTempDir('om-librarian-policy');
        $this->assertSame($this->directory, realpath($this->directory));
        $this->assertFalse(is_link($this->directory));
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->directory);
    }

    public function testNonGitProjectNeverInvokesGit(): void
    {
        $exec = $this->createMock(ExecInterface::class);
        $exec->expects($this->never())->method('exec');
        $this->assertTrue((new LibrarianProjectPolicy($exec, new NullLogger()))->allows($this->directory));
    }

    public function testPrimaryCheckoutAndGitFileWithSharedMetadataAreAllowed(): void
    {
        mkdir($this->directory.'/metadata');
        // A submodule or --separate-git-dir checkout also has a .git file.
        file_put_contents($this->directory.'/.git', 'gitdir: metadata');
        $exec = $this->createMock(ExecInterface::class);
        $exec->expects($this->once())->method('exec')->with('git', [
            '-c', 'safe.directory='.$this->directory,
            'rev-parse', '--path-format=absolute', '--git-dir', '--git-common-dir',
        ], $this->callback(fn (ExecOptionsDTO $options): bool => $options->cwd === $this->directory && 5.0 === $options->timeout))
            ->willReturn(new ExecResultDTO($this->directory.'/metadata'."\n".$this->directory.'/metadata/.', '', 0));
        $this->assertTrue((new LibrarianProjectPolicy($exec, new NullLogger()))->allows($this->directory));
    }

    public function testLinkedWorktreeRemainsLocalFromNestedDirectory(): void
    {
        mkdir($this->directory.'/metadata');
        mkdir($this->directory.'/metadata/worktree');
        mkdir($this->directory.'/src');
        file_put_contents($this->directory.'/.git', 'gitdir: metadata/worktree');
        $exec = $this->createStub(ExecInterface::class);
        $exec->method('exec')->willReturn(new ExecResultDTO($this->directory.'/metadata/worktree'."\n".$this->directory.'/metadata', '', 0));
        $this->assertFalse((new LibrarianProjectPolicy($exec, new NullLogger()))->allows($this->directory.'/src'));
    }

    public function testUnavailableGitMetadataCannotEnableExternalRetrieval(): void
    {
        file_put_contents($this->directory.'/.git', 'gitdir: missing');
        $exec = $this->createStub(ExecInterface::class);
        $exec->method('exec')->willReturn(new ExecResultDTO('', 'unavailable', 128));
        $this->assertFalse((new LibrarianProjectPolicy($exec, new NullLogger()))->allows($this->directory));
    }
}
