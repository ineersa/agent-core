<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\HatfieldExt\TaskWorkflow\DTO\WorkspaceBindingDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Exec\GitExecutor;
use Ineersa\HatfieldExt\TaskWorkflow\Tests\Support\WorkspaceFixture;
use Ineersa\HatfieldExt\TaskWorkflow\Worktree\WorktreeManager;
use PHPUnit\Framework\TestCase;

final class WorktreeManagerTest extends TestCase
{
    private WorkspaceFixture $fixture;
    private WorktreeManager $manager;

    protected function setUp(): void
    {
        $this->fixture = new WorkspaceFixture();
        $this->manager = new WorktreeManager($this->fixture->git);
    }

    protected function tearDown(): void
    {
        $this->fixture->close();
    }

    public function testCreateRecoverAndRemoveKeepsBranch(): void
    {
        $root = $this->fixture->root;
        $path = $this->fixture->directory.'/task';
        $binding = $this->manager->create($root, 'task/1', $path, 'main');
        $this->assertSame($root, $this->manager->primaryRoot($path));
        $this->assertEquals($binding, $this->manager->create($path, 'task/1', $path, 'main'));
        $this->assertTrue($this->manager->isClean($path));
        $this->manager->remove($root, $binding, $this->manager->head($path));
        $this->assertTrue($this->manager->verifyRemoved($root, $path));
        $this->assertTrue($this->fixture->git->branchExists($root, 'task/1'));
        $this->manager->remove($root, $binding);
    }

    public function testRegisteredMissingDirectoryIsRemovedFromRegistry(): void
    {
        $binding = $this->manager->create($this->fixture->root, 'task/missing', $this->fixture->directory.'/missing', 'main');
        TestDirectoryIsolation::removeDirectory($binding->worktree);
        $this->assertFalse($this->manager->verifyRemoved($this->fixture->root, $binding->worktree));
        $this->manager->remove($this->fixture->root, $binding);
        $this->assertTrue($this->manager->verifyRemoved($this->fixture->root, $binding->worktree));
    }

    public function testUnregisteredPresentAndDanglingSymlinkArePreserved(): void
    {
        $path = $this->fixture->directory.'/unregistered';
        mkdir($path);
        foreach ([false, true] as $dangling) {
            if ($dangling) {
                rmdir($path);
                symlink($this->fixture->directory.'/absent', $path);
            }
            $this->assertFalse($this->manager->verifyRemoved($this->fixture->root, $path));
            try {
                $this->manager->remove($this->fixture->root, new WorkspaceBindingDTO('task/unregistered', $path));
                $this->fail('Expected refusal');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('preserving', $e->getMessage());
            }
            $this->assertNotFalse(lstat($path));
        }
    }

    public function testDirtyAndUnexpectedCommittedHeadArePreserved(): void
    {
        $binding = $this->manager->create($this->fixture->root, 'task/valuable', $this->fixture->directory.'/valuable', 'main');
        file_put_contents($binding->worktree.'/valuable', 'keep');
        try {
            $this->manager->remove($this->fixture->root, $binding);
            $this->fail('Expected dirty refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('valuable', $e->getMessage());
        }
        $old = $this->manager->head($binding->worktree);
        $this->fixture->git->gitOk(['add', '.'], $binding->worktree);
        $this->fixture->git->gitOk(['commit', '-m', 'valuable'], $binding->worktree);
        try {
            $this->manager->remove($this->fixture->root, $binding, $old);
            $this->fail('Expected HEAD refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HEAD', $e->getMessage());
        }
        $this->assertFileExists($binding->worktree.'/valuable');
    }

    public function testFastForwardUpdatesOnlyCleanPrimaryBase(): void
    {
        $root = $this->fixture->root;
        $remote = $this->fixture->directory.'/remote.git';
        $this->fixture->git->gitOk(['clone', '--bare', $root, $remote], $root);
        $this->fixture->git->gitOk(['remote', 'add', 'origin', $remote], $root);
        $binding = $this->manager->create($root, 'task/advance', $this->fixture->directory.'/advance', 'main');
        $this->fixture->git->gitOk(['commit', '--allow-empty', '-m', 'advance'], $binding->worktree);
        $this->fixture->git->gitOk(['push', 'origin', 'HEAD:main'], $binding->worktree);
        $this->manager->fastForwardBase($binding->worktree, 'main');
        $this->assertSame($this->manager->head($binding->worktree), $this->manager->head($root));
        file_put_contents($root.'/valuable', 'keep');
        $this->expectException(\RuntimeException::class);
        $this->manager->fastForwardBase($root, 'main');
    }

    public function testUnrelatedFetchCannotChangeTheBaseRevisionBeingMerged(): void
    {
        $root = $this->fixture->root;
        $git = $this->fixture->git;
        $git->gitOk(['clone', '--bare', $root, $this->fixture->directory.'/remote.git'], $root);
        $git->gitOk(['remote', 'add', 'origin', $this->fixture->directory.'/remote.git'], $root);
        $base = $this->manager->create($root, 'base-update', $this->fixture->directory.'/base-update', 'main');
        $git->gitOk(['commit', '--allow-empty', '-m', 'merged base revision'], $base->worktree);
        $git->gitOk(['push', 'origin', 'HEAD:main'], $base->worktree);
        $intended = $this->manager->head($base->worktree);
        $other = $this->manager->create($root, 'unmerged', $this->fixture->directory.'/unmerged', 'main');
        $git->gitOk(['commit', '--allow-empty', '-m', 'unmerged revision'], $other->worktree);
        $git->gitOk(['push', 'origin', 'unmerged'], $other->worktree);
        $interfered = false;
        $exec = new StubExec(function (string $command, array $args, ?ExecOptionsDTO $options) use ($root, $git, &$interfered): ExecResultDTO {
            $result = $this->fixture->exec($command, $args, $options);
            if ('fetch' === $args[0] && !$interfered) {
                // Synchronous interference, after the target fetch completed:
                // replace shared FETCH_HEAD before the manager resolves/merges.
                $git->gitOk(['fetch', 'origin', 'unmerged'], $root);
                $interfered = true;
            }

            return $result;
        });
        (new WorktreeManager(new GitExecutor($exec)))->fastForwardBase($root, 'main');
        $this->assertTrue($interfered);
        $this->assertSame($intended, $this->manager->head($root));
        $this->assertNotSame($intended, trim($git->gitOk(['rev-parse', 'FETCH_HEAD'], $root)->stdout));
        $ref = 'refs/hatfield/base-update/'.hash('sha256', "origin\0main");
        $this->assertSame($intended, trim($git->gitOk(['rev-parse', $ref], $root)->stdout));
    }

    public function testSubstitutedForeignCheckoutIsNotOwnedDespiteStaleRegistration(): void
    {
        $root = $this->fixture->root;
        $path = $this->fixture->directory.'/task';
        $binding = $this->manager->create($root, 'task/substitute', $path, 'main');
        rename($path, $this->fixture->directory.'/parked');
        $this->fixture->git->gitOk(['clone', '--no-hardlinks', $root, $path], $root);
        $this->fixture->git->gitOk(['checkout', '-b', $binding->branch, 'origin/'.$binding->branch], $path);
        foreach ([
            fn () => $this->manager->assertOwned($root, $binding),
            fn () => $this->manager->create($root, $binding->branch, $path, 'main'),
            fn () => $this->manager->remove($root, $binding),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected substituted checkout refusal');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('identity', $exception->getMessage());
            }
        }
        $this->assertDirectoryExists($path.'/.git');
    }

    public function testPrimaryAndMismatchedBranchAreRefused(): void
    {
        try {
            $this->manager->remove($this->fixture->root, new WorkspaceBindingDTO('main', $this->fixture->root));
            $this->fail('Expected primary refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('primary', $e->getMessage());
        }
        $binding = $this->manager->create($this->fixture->root, 'task/right', $this->fixture->directory.'/right', 'main');
        $this->expectException(\RuntimeException::class);
        $this->manager->remove($this->fixture->root, new WorkspaceBindingDTO('task/wrong', $binding->worktree));
    }
}
