<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\HatfieldExt\TaskWorkflow\Tests\Support\WorkspaceFixture;
use Ineersa\HatfieldExt\TaskWorkflow\Worktree\WorkspaceStateStore;
use Ineersa\HatfieldExt\TaskWorkflow\Worktree\WorktreeManager;
use PHPUnit\Framework\TestCase;

final class WorkspaceStateStoreTest extends TestCase
{
    public function testSharedRecoverySurvivesRemovalAndInvalidatesSetupBeforeCleanup(): void
    {
        $fixture = new WorkspaceFixture();
        try {
            $store = new WorkspaceStateStore($fixture->git, $fixture->root);
            $path = $fixture->directory.'/task';
            $first = $store->lock('owner/repo', 1)->withLock(static fn () => $store->initialize('owner/repo', 1, 'https://example.invalid/1', 'task/1', $path));
            $this->assertEquals($first, $store->initialize('owner/repo', 1, 'https://example.invalid/1', 'task/1', $path));
            $this->assertDirectoryExists($first['data_dir']);
            $this->assertStringNotContainsString($path.'/', $first['data_dir']);
            $manager = new WorktreeManager($fixture->git);
            $binding = $manager->create($fixture->root, 'task/1', $path, 'main');
            $linked = new WorkspaceStateStore($fixture->git, $path);
            $this->assertEquals($first, $linked->load('owner/repo', 1));
            $first['setup_complete'] = true;
            $first['setup_attempted'] = true;
            $store->save('owner/repo', 1, $first);
            $cleanup = $store->beginCleanup('owner/repo', 1, 'done');
            $this->assertFalse($cleanup['setup_complete']);
            $this->assertSame('done', $cleanup['pending_operation']);
            $this->assertSame($first['resource_id'], $cleanup['resource_id']);
            $manager->remove($fixture->root, $binding);
            $this->assertEquals([$cleanup], $store->records('owner/repo'));
            $this->assertEquals($cleanup, $store->load('owner/repo', 1));
            $second = $store->initialize('owner/repo', 2, 'url', '', '');
            $this->assertNotSame($first['resource_id'], $second['resource_id']);
            $store->forget('owner/repo', 1);
            $this->assertNull($store->load('owner/repo', 1));
            $this->assertDirectoryDoesNotExist($first['data_dir']);
        } finally {
            $fixture->close();
        }
    }

    public function testConflictingCleanupDoesNotOverwriteRecovery(): void
    {
        $fixture = new WorkspaceFixture();
        try {
            $store = new WorkspaceStateStore($fixture->git, $fixture->root);
            $store->initialize('owner/repo', 1, 'url', '', '');
            $store->beginCleanup('owner/repo', 1, 'cancel');
            $this->expectException(\RuntimeException::class);
            $store->beginCleanup('owner/repo', 1, 'done');
        } finally {
            $fixture->close();
        }
    }
}
