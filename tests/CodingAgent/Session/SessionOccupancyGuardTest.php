<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\SessionOccupancyGuard;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class SessionOccupancyGuardTest extends TestCase
{
    private string $lockDir;

    private string $projectCwd;

    protected function setUp(): void
    {
        $this->lockDir = TestDirectoryIsolation::createOsTempDir('hatfield-occupancy-lock');
        $this->projectCwd = TestDirectoryIsolation::createOsTempDir('hatfield-occupancy-cwd');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->lockDir);
        TestDirectoryIsolation::removeDirectory($this->projectCwd);
    }

    private function occupancyLockKey(string $cwd, string $sessionId): string
    {
        return 'hatfield-tui-occupancy-'.$cwd.':'.$sessionId;
    }

    private function createAppConfig(string $cwd): AppConfig
    {
        return new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $cwd,
        );
    }

    private function createGuard(?string $cwd = null): SessionOccupancyGuard
    {
        $cwd ??= $this->projectCwd;

        return new SessionOccupancyGuard(
            new LockFactory(new FlockStore($this->lockDir)),
            $this->createAppConfig($cwd),
        );
    }

    public function testActivatingAcquiresAndDeactivatingReleases(): void
    {
        $guard = $this->createGuard();
        $sessionId = 'sess-'.bin2hex(random_bytes(4));

        self::assertTrue($guard->tryAcquire($sessionId));
        self::assertTrue($guard->tryAcquire($sessionId), 'Same guard re-acquires same session after internal release');

        $guard->release();

        self::assertTrue($guard->tryAcquire($sessionId), 'Lock must be free after release');
    }

    public function testSecondGuardDeniedWhenLockHeldByAnotherFactoryOnSameStore(): void
    {
        $sessionId = 'sess-'.bin2hex(random_bytes(4));
        $lockName = $this->occupancyLockKey($this->projectCwd, $sessionId);
        $factory = new LockFactory(new FlockStore($this->lockDir));

        $external = $factory->createLock($lockName);
        self::assertTrue($external->acquire(false));

        $guard = $this->createGuard();
        self::assertFalse($guard->tryAcquire($sessionId));

        $external->release();
    }

    public function testCrossProcessChildHoldsLockUntilExit(): void
    {
        $sessionId = 'sess-'.bin2hex(random_bytes(4));
        $lockName = $this->occupancyLockKey($this->projectCwd, $sessionId);

        $childScript = <<<'CHILD'
<?php
declare(strict_types=1);
$lockDir = $argv[1];
$lockName = $argv[2];
$autoload = $argv[3];
require $autoload;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
$lock = (new LockFactory(new FlockStore($lockDir)))->createLock($lockName);
if (!$lock->acquire(false)) {
    fwrite(STDERR, "child failed to acquire\n");
    exit(2);
}
fwrite(STDOUT, "holding\n");
fflush(STDOUT);
sleep(30);
CHILD;

        $childPath = $this->lockDir.'/child-hold.php';
        file_put_contents($childPath, $childScript);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $autoload = dirname(__DIR__, 3).'/vendor/autoload.php';
        $cmd = [\PHP_BINARY, $childPath, $this->lockDir, $lockName, $autoload];
        $process = proc_open($cmd, $descriptors, $pipes, $this->lockDir);
        self::assertIsResource($process);

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $chunk = stream_get_contents($pipes[1]);
            if (is_string($chunk) && $chunk !== '') {
                $stdout .= $chunk;
            }
            if (str_contains($stdout, 'holding')) {
                break;
            }
            usleep(50_000);
        }
        $stderr = (string) stream_get_contents($pipes[2]);
        self::assertStringContainsString('holding', $stdout, 'child stdout: '.$stdout.' stderr: '.$stderr);

        $guard = $this->createGuard();
        self::assertFalse($guard->tryAcquire($sessionId));

        proc_terminate($process, \SIGTERM);
        proc_close($process);
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
        }

        // OS flock release on child process death is the core crash-safety
        // contract (flock over PID files). Prove the lock is reclaimable.
        $reclaimed = false;
        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            if ($guard->tryAcquire($sessionId)) {
                $reclaimed = true;
                $guard->release();
                break;
            }
            usleep(20_000);
        }
        self::assertTrue($reclaimed, 'Lock must be released after child process exits (OS flock reclaim on death)');
    }

    public function testDifferentCwdSameSessionIdDoesNotCollide(): void
    {
        $sessionId = '1';
        $otherCwd = TestDirectoryIsolation::createOsTempDir('hatfield-occupancy-cwd-other');
        try {
            $lockName = $this->occupancyLockKey($this->projectCwd, $sessionId);
            $factory = new LockFactory(new FlockStore($this->lockDir));
            $external = $factory->createLock($lockName);
            self::assertTrue($external->acquire(false));

            $guardOtherCwd = new SessionOccupancyGuard(
                new LockFactory(new FlockStore($this->lockDir)),
                $this->createAppConfig($otherCwd),
            );
            self::assertTrue($guardOtherCwd->tryAcquire($sessionId), 'Different project cwd must not share occupancy lock');

            $external->release();
        } finally {
            TestDirectoryIsolation::removeDirectory($otherCwd);
        }
    }
}
