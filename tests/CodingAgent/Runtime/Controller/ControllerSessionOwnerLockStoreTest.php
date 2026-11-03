<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\CodingAgent\Runtime\Controller\ControllerSessionOwnerLockStore;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

final class ControllerSessionOwnerLockStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TestDirectoryIsolation::createProjectTempDir('test-controller-lock');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->root);
    }

    public function testSessionsRemainIndependentAndCompletedDirectoriesAreReclaimed(): void
    {
        $factory = new LockFactory(new ControllerSessionOwnerLockStore($this->root));
        $one = $factory->createLock($this->resource('one'), ttl: null);
        $duplicate = $factory->createLock($this->resource('one'), ttl: null);
        $two = $factory->createLock($this->resource('two'), ttl: null);
        $this->assertTrue($one->acquire(false));
        $this->assertFalse($duplicate->acquire(false));
        $this->assertTrue($two->acquire(false));
        $one->release();
        $one->release();
        $this->assertDirectoryDoesNotExist($this->directory('one'));
        $this->assertTrue($two->isAcquired());
        $two->release();
        $this->assertSame([], glob($this->root.'/managed-*') ?: []);
        $this->assertCount(1, glob($this->root.'/sf.*.lock') ?: [], 'Only the permanent coordinator remains.');
    }

    public function testPendingContenderCannotOpenSessionDirectoryBeforeCoordinator(): void
    {
        $this->requireOwnedProcesses();
        $guard = (new LockFactory(new FlockStore($this->root)))->createLock('controller-session-directory-lifecycle', ttl: null);
        $guard->acquire();
        $input = new InputStream();
        $child = $this->child(<<<'PHP'
$factory = new \Symfony\Component\Lock\LockFactory(new \Ineersa\CodingAgent\Runtime\Controller\ControllerSessionOwnerLockStore($root));
echo "OPENING\n"; fflush(STDOUT);
$lock = $factory->createLock($resource, ttl: null);
if (!$lock->acquire(false)) { throw new \RuntimeException('Session acquisition failed.'); }
echo "HELD\n"; fflush(STDOUT); fgets(STDIN);
$lock->release();
PHP);
        $child->setInput($input);
        try {
            $child->start();
            $this->assertTrue($child->waitUntil(static fn ($type, $output) => str_contains($output, 'OPENING')));
            $this->assertTrue($child->isRunning());
            $this->assertDirectoryDoesNotExist($this->directory('one'), 'A pending contender has not opened the session inode.');
            $guard->release();
            $this->assertTrue($child->waitUntil(static fn ($type, $output) => str_contains($output, 'HELD')));
            $other = (new LockFactory(new ControllerSessionOwnerLockStore($this->root)))->createLock($this->resource('one'), ttl: null);
            $this->assertFalse($other->acquire(false));
            $input->close();
            $this->assertSame(0, $child->wait());
            $this->assertDirectoryDoesNotExist($this->directory('one'));
        } finally {
            $guard->release();
            $input->close();
            $child->stop();
        }
    }

    public function testCrashRecoveryRetainsInheritedConsumerUntilItsBarrierCloses(): void
    {
        $this->requireOwnedProcesses();
        $barrier = $this->root.'/barrier';
        $this->assertTrue(posix_mkfifo($barrier, 0600));
        $release = fopen($barrier, 'r+b');
        $producer = $this->child(<<<'PHP'
$factory = new \Symfony\Component\Lock\LockFactory(new \Ineersa\CodingAgent\Runtime\Controller\ControllerSessionOwnerLockStore($root));
$lock = $factory->createLock($resource, ttl: null, autoRelease: false);
if (!$lock->acquire(false)) { throw new \RuntimeException('Producer could not acquire session.'); }
$script = '$pipe = fopen($argv[1], "r+"); $held = false; foreach (glob("/proc/self/fd/*") as $fd) { $path = @readlink($fd); if (false !== $path && str_contains($path, "/managed-")) { $held = true; } } if (!$held) { exit(2); } echo "CONSUMER_READY\\n"; fflush(STDOUT); fgets($pipe);';
$consumer = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $script, $root.'/barrier'], env: ['HATFIELD_SESSION_ID' => false], timeout: 5);
$consumer->start();
if (!$consumer->waitUntil(static fn ($type, $output) => str_contains($output, 'CONSUMER_READY'))) { throw new \RuntimeException('Inherited consumer not ready.'); }
echo "READY\n"; fflush(STDOUT); fgets(STDIN);
PHP);
        $input = new InputStream();
        $producer->setInput($input);
        $probe = $this->child(<<<'PHP'
$directory = $root.'/managed-'.hash('sha256', $resource);
$lock = (new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\FlockStore($directory)))->createLock($resource, ttl: null);
// Block in the kernel on the existing inode, without managed reclamation.
if (!$lock->acquire(true)) { throw new \RuntimeException('Inherited inode acquisition failed.'); }
echo "ACQUIRED\n"; fflush(STDOUT);
$lock->release();
PHP);
        $probe->setTimeout(3);
        try {
            $producer->start();
            $this->assertTrue($producer->waitUntil(static fn ($type, $output) => str_contains($output, 'READY')), $producer->getErrorOutput());
            $producer->signal(\SIGKILL);
            $producer->wait();
            $factory = new LockFactory(new ControllerSessionOwnerLockStore($this->root));
            $two = $factory->createLock($this->resource('two'), ttl: null);
            $this->assertTrue($two->acquire(false));
            $this->assertDirectoryExists($this->directory('one'));
            $one = $factory->createLock($this->resource('one'), ttl: null);
            $this->assertFalse($one->acquire(false));
            $inodes = glob($this->directory('one').'/sf.*.lock');
            $this->assertCount(1, $inodes);
            $inode = fileinode($inodes[0]);
            $probe->start();
            fwrite($release, "\n");
            // Pipe notification follows blocking acquisition, not an elapsed
            // interval or process-liveness snapshot. Timeout is a safety cap.
            $this->assertTrue($probe->waitUntil(static fn ($type, $output) => str_contains($output, 'ACQUIRED')), $probe->getErrorOutput());
            $this->assertSame(0, $probe->wait());
            clearstatcache(true, $inodes[0]);
            $this->assertSame($inode, fileinode($inodes[0]), 'The probe must acquire the inherited inode, not create a replacement.');
            $three = $factory->createLock($this->resource('three'), ttl: null);
            $this->assertTrue($three->acquire(false));
            $this->assertDirectoryDoesNotExist($this->directory('one'));
            $three->release();
            $two->release();
        } finally {
            fwrite($release, "\n");
            fclose($release);
            $input->close();
            $producer->stop();
            $probe->stop();
        }
    }

    public function testLegacyAndUnknownDirectoriesAreNotAdopted(): void
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->root.'/sf.legacy.lock', '');
        $filesystem->mkdir($this->root.'/managed-'.str_repeat('a', 64));
        $factory = new LockFactory(new ControllerSessionOwnerLockStore($this->root));
        $lock = $factory->createLock($this->resource('one'), ttl: null);
        $this->assertTrue($lock->acquire(false));
        $lock->release();
        $this->assertFileExists($this->root.'/sf.legacy.lock');
        $this->assertDirectoryExists($this->root.'/managed-'.str_repeat('a', 64));
        $filesystem->symlink($this->root.'/managed-'.str_repeat('a', 64), $this->directory('two'));
        $this->expectException(LockAcquiringException::class);
        $factory->createLock($this->resource('two'), ttl: null)->acquire(false);
    }

    private function resource(string $id): string
    {
        return 'hatfield.controller.session.'.hash('sha256', $this->root."\0".$id);
    }

    private function directory(string $id): string
    {
        return $this->root.'/managed-'.hash('sha256', $this->resource($id));
    }

    private function requireOwnedProcesses(): void
    {
        if (0 === posix_geteuid()) {
            $this->markTestSkipped('Never signal root-owned processes.');
        }
    }

    private function child(string $body): Process
    {
        $script = 'require '.var_export(ProjectDir::get().'/vendor/autoload.php', true).'; $root=$argv[1]; $resource=$argv[2]; '.$body;

        return new Process([\PHP_BINARY, '-r', $script, $this->root, $this->resource('one')], env: ['HATFIELD_SESSION_ID' => false], timeout: 5);
    }
}
