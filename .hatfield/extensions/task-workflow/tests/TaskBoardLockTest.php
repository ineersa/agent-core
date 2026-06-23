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
