<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Ineersa\CodingAgent\Extension\ExtensionExecBridge;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCancellationTokenInterface;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskBoardLock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Amp\Socket\listen;

final class TaskBoardLockTest extends TestCase
{
    public function testCommandHoldingBoardLockCanFinishWhileAnotherFiberWaits(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tw-command-lock');
        $server = listen('127.0.0.1:0');
        $lock = new TaskBoardLock(TaskBoardLock::lockPathForRoot($dir));
        $holder = \Amp\async(static fn () => $lock->withLock(static fn () => new ExtensionExecBridge()->exec(\PHP_BINARY, ['-r', '$c=stream_socket_client($argv[1]); fwrite($c,"READY\n"); fgets($c); echo "released";', 'tcp://'.$server->getAddress()->toString()], new ExecOptionsDTO(timeout: 3))));
        $socket = null;
        $waiter = null;
        try {
            $socket = $server->accept(new TimeoutCancellation(2));
            $this->assertNotNull($socket);
            $this->assertSame("READY\n", $socket->read(new TimeoutCancellation(2)));
            $entered = new DeferredFuture();
            $waiter = \Amp\async(static function () use ($lock, $entered): mixed {
                $entered->complete();

                return $lock->withLock(static fn () => 'acquired');
            });
            $entered->getFuture()->await(new TimeoutCancellation(2));
            $this->assertFalse($holder->isComplete());
            $this->assertFalse($waiter->isComplete());
            $socket->write("release\n");
            $this->assertSame('released', $holder->await(new TimeoutCancellation(2))->stdout);
            $this->assertSame('acquired', $waiter->await(new TimeoutCancellation(2)));
        } finally {
            $socket?->close();
            $server->close();
            $holder->await(new TimeoutCancellation(4));
            $waiter?->await(new TimeoutCancellation(2));
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    public function testContendedBoardWaitYieldsForReleaseAndCancellation(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tw-lock-fiber');
        try {
            foreach ([false, true] as $cancel) {
                $path = TaskBoardLock::lockPathForRoot($dir);
                $owner = fopen($path, 'c+b');
                $this->assertTrue(flock($owner, \LOCK_EX | \LOCK_NB));
                $cancelled = false;
                $token = $this->createStub(ToolCancellationTokenInterface::class);
                $token->method('isCancellationRequested')->willReturnCallback(static function () use (&$cancelled): bool { return $cancelled; });
                $entered = new DeferredFuture();
                $mutated = false;
                $lock = new TaskBoardLock($path);
                $waiting = \Amp\async(static function () use ($lock, $token, $entered, &$mutated): mixed {
                    $entered->complete();

                    return $lock->withLock(static function () use (&$mutated): string {
                        $mutated = true;

                        return 'done';
                    }, $token);
                });
                try {
                    $entered->getFuture()->await(new TimeoutCancellation(2));
                    \Amp\async(static function () use ($waiting, &$mutated): void {
                        self::assertFalse($waiting->isComplete());
                        self::assertFalse($mutated);
                    })->await(new TimeoutCancellation(2));
                    if ($cancel) {
                        $cancelled = true;
                        $result = $waiting->await(new TimeoutCancellation(2));
                        $this->assertTrue($result['cancelled']);
                        $this->assertFalse($mutated);
                    }
                    flock($owner, \LOCK_UN);
                    if (!$cancel) {
                        $this->assertSame('done', $waiting->await(new TimeoutCancellation(2)));
                        $this->assertTrue($mutated);
                    }
                    $this->assertSame('reused', $lock->withLock(static fn (): string => 'reused'));
                } finally {
                    $cancelled = true;
                    flock($owner, \LOCK_UN);
                    fclose($owner);
                }
            }
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

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

    #[Test]
    public function withLockReturnsCancelledWhenTokenFlipsDuringWait(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tw-lock-cancel');
        try {
            $lockPath = TaskBoardLock::lockPathForRoot($dir);
            $holder = fopen($lockPath, 'c+b');
            $this->assertNotFalse($holder);
            $this->assertTrue(flock($holder, \LOCK_EX | \LOCK_NB));

            $token = new class implements ToolCancellationTokenInterface {
                private int $checks = 0;

                public function isCancellationRequested(): bool
                {
                    return ++$this->checks >= 2;
                }
            };

            try {
                $lock = new TaskBoardLock($lockPath);
                $result = $lock->withLock(static fn (): string => 'should-not-run', $token);
                $this->assertIsArray($result);
                $this->assertTrue($result['cancelled'] ?? false);
            } finally {
                flock($holder, \LOCK_UN);
                fclose($holder);
            }
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }
}
