<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\BackgroundProcess;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: isAlive must not treat PHP-stat-cached /proc rows or unreaped
 * zombies as running processes.
 */
#[CoversClass(ProcessLifecycle::class)]
final class ProcessLifecycleTest extends TestCase
{
    private string $tmpDir;
    private ProcessLifecycle $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('process-lifecycle');
        $this->lifecycle = new ProcessLifecycle(
            new BackgroundProcessConfig(storageDir: $this->tmpDir, stopGraceSeconds: 0),
            new TestLogger(),
        );
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);

        parent::tearDown();
    }

    #[Test]
    public function isAliveRejectsNonPositivePids(): void
    {
        $this->assertFalse($this->lifecycle->isAlive(0));
        $this->assertFalse($this->lifecycle->isAlive(-1));
    }

    #[Test]
    public function isAliveIgnoresPhpStatCacheAfterProcessExit(): void
    {
        $launched = $this->lifecycle->launchProcess(
            'exec sleep 30',
            $this->tmpDir.'/cache.pid',
            $this->tmpDir.'/cache.log',
            $this->tmpDir.'/cache.status',
        );
        $pid = $launched['pid'];

        $this->assertTrue($this->lifecycle->isAlive($pid));
        $this->lifecycle->sendKill($pid, $launched['pgid']);

        // Positive readiness: isAlive must flip false without raising the
        // historical 1s assertProcessStopped poll. Bound is a safety cap.
        $deadline = hrtime(true) + 200_000_000;
        while (hrtime(true) < $deadline && $this->lifecycle->isAlive($pid)) {
            usleep(1_000);
        }

        $this->assertFalse(
            $this->lifecycle->isAlive($pid),
            'Warm /proc is_dir() cache must not keep a killed PID alive',
        );
    }

    #[Test]
    public function isAliveTreatsZombieProcessAsDead(): void
    {
        $pipes = [];
        $process = proc_open(
            ['bash', '-lc', 'sleep 30'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        try {
            $status = proc_get_status($process);
            $pid = $status['pid'];
            $this->assertGreaterThan(0, $pid);
            $this->assertTrue($this->lifecycle->isAlive($pid));

            posix_kill($pid, \SIGKILL);

            $deadline = hrtime(true) + 200_000_000;
            $becameZombie = false;
            while (hrtime(true) < $deadline) {
                $stat = @file_get_contents('/proc/'.$pid.'/stat');
                if (false !== $stat) {
                    $closeParen = strrpos($stat, ')');
                    $state = false === $closeParen ? '' : ($stat[$closeParen + 2] ?? '');
                    if ('Z' === $state) {
                        $becameZombie = true;
                        break;
                    }
                }
                usleep(1_000);
            }

            $this->assertTrue($becameZombie, 'Child must become a zombie before reaping');
            $this->assertFalse(
                $this->lifecycle->isAlive($pid),
                'Unreaped zombie /proc entries must not count as alive',
            );
        } finally {
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
        }
    }
}
