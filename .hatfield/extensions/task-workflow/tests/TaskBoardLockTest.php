<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskBoardLock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TaskBoardLockTest extends TestCase
{
    #[Test]
    public function withLockReleasesOnException(): void
    {
        // Thesis: if flock release is moved out of finally, a thrown callback would deadlock the next withLock call.
        $dir = TestDirectoryIsolation::createProjectTempDir('tw-lock-ex');
        try {
            $lockPath = TaskBoardLock::lockPathForRoot($dir);
            $lock = new TaskBoardLock($lockPath);

            try {
                $lock->withLock(static function (): void {
                    throw new \RuntimeException('boom');
                });
                $this->fail('Expected RuntimeException');
            } catch (\RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }

            $acquired = $lock->withLock(static fn (): string => 'ok');
            $this->assertSame('ok', $acquired);
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    #[Test]
    public function lockSerializesAccess(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tw-lock');
        try {
            $lockPath = TaskBoardLock::lockPathForRoot($dir);
            $lock = new TaskBoardLock($lockPath);
            $ran = $lock->withLock(static fn (): int => 42);
            $this->assertSame(42, $ran);
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }
}
