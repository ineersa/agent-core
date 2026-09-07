<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\BackgroundProcess;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return iterable<string, array{bool}> */
    public static function exitedChildren(): iterable
    {
        yield 'reaped child with warm stat cache' => [true];
        yield 'unreaped zombie' => [false];
    }

    #[Test]
    #[DataProvider('exitedChildren')]
    public function isAliveRecognizesExitedChild(bool $reap): void
    {
        $pipes = [];
        $process = proc_open(
            [\PHP_BINARY, '-r', 'fread(STDIN, 1);'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        try {
            $status = proc_get_status($process);
            $pid = $status['pid'];
            $this->assertGreaterThan(0, $pid);
            $this->assertTrue($this->lifecycle->isAlive($pid));

            // Closing stdin releases the child. No delayed fixture or signal race.
            fclose($pipes[0]);
            if ($reap) {
                $this->assertSame(0, proc_close($process));
                $this->assertFalse($this->lifecycle->isAlive($pid));

                return;
            }

            // Keep ownership without waitpid/proc_get_status, which would reap it.
            $deadline = hrtime(true) + 2_000_000_000;
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
            if (\is_resource($process)) {
                proc_terminate($process, \SIGKILL);
                proc_close($process);
            }
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }
    }
}
