<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\Edit;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tool\Edit\EditLockDiagnosticsService;
use PHPUnit\Framework\TestCase;

final class EditLockDiagnosticsServiceTest extends TestCase
{
    public function testReplacedPathDoesNotMisidentifyOpenedInode(): void
    {
        $dir = TestDirectoryIsolation::createOsTempDir('edit-lock-evidence');
        $path = $dir.'/target.txt';
        file_put_contents($path, 'private old bytes');
        $opened = fopen($path, 'r+b');
        $this->assertIsResource($opened);
        try {
            $inode = fstat($opened)['ino'];
            // Keep the original open while replacing the name with another inode.
            file_put_contents($dir.'/replacement', 'private replacement bytes');
            rename($dir.'/replacement', $path);
            $logger = new TestLogger();
            (new EditLockDiagnosticsService($logger, new StackToolExecutionContextAccessor()))->logTimeout($path, $opened, 5.1, 20);
            $evidence = $logger->records[0]['context'];
            $this->assertTrue($evidence['path_identity_changed']);
            $this->assertSame($inode, $evidence['opened_file']['inode']);
            $this->assertSame(0, $evidence['opened_file']['links']);
            $this->assertNotSame($inode, $evidence['current_path_file']['inode']);
            $this->assertNull($evidence['run_id']);
            $this->assertStringNotContainsString('private', json_encode($evidence, \JSON_THROW_ON_ERROR));
        } finally {
            fclose($opened);
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    public function testSameProcessHardlinkLockIsCapturedWithoutUnrelatedLocks(): void
    {
        $dir = TestDirectoryIsolation::createOsTempDir('edit-lock-hardlinks');
        $path = $dir.'/target.txt';
        file_put_contents($path, 'secret');
        link($path, $dir.'/alias.txt');
        file_put_contents($dir.'/unrelated.txt', 'other secret');
        $owner = fopen($dir.'/alias.txt', 'r+b');
        $opened = fopen($path, 'r+b');
        $unrelated = fopen($dir.'/unrelated.txt', 'r+b');
        try {
            $this->assertTrue(flock($owner, \LOCK_EX | \LOCK_NB));
            $this->assertTrue(flock($unrelated, \LOCK_EX | \LOCK_NB));
            $logger = new TestLogger();
            (new EditLockDiagnosticsService($logger, new StackToolExecutionContextAccessor()))->logTimeout($path, $opened, 5.0, 10);
            $evidence = $logger->records[0]['context'];
            $this->assertSame(2, $evidence['opened_file']['links']);
            if ('Linux' === \PHP_OS_FAMILY && 'read' === $evidence['kernel_locks']['status']) {
                $this->assertCount(1, $evidence['kernel_locks']['records']);
                $lock = $evidence['kernel_locks']['records'][0];
                $this->assertSame(getmypid(), $lock['pid']);
                $this->assertSame('FLOCK', $lock['type']);
                $this->assertSame('WRITE', $lock['mode']);
                $this->assertSame((string) fstat($opened)['ino'], $lock['inode']);
                $this->assertCount(1, $evidence['processes']);
                $this->assertCount(2, $evidence['processes'][0]['matching_fds']);
                $this->assertContains(true, array_column($evidence['processes'][0]['matching_fds'], 'has_lock_record'));
            }
            $this->assertStringNotContainsString('secret', json_encode($evidence, \JSON_THROW_ON_ERROR));
        } finally {
            fclose($owner);
            fclose($opened);
            fclose($unrelated);
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }
}
