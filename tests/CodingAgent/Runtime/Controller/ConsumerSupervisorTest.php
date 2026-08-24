<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Runtime\Controller\ConsumerSupervisor;
use Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Controller\ConsumerSupervisor
 */
final class ConsumerSupervisorTest extends TestCase
{
    private TestLogger $logger;

    /**
     * Thesis: shutdown must SIGTERM every tracked consumer before waiting on one
     * shared grace deadline. Sequential Process::stop(grace) delays the last
     * child by the full grace and orphaned the linux-arm64 extension_agent.
     */
    public function testShutdownSignalsAllTrackedConsumersBeforeSharedGraceWait(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('consumer-shutdown-term');
        $script = $dir.'/consumer.php';
        $hangReady = $dir.'/hang.ready';
        $exitReady = $dir.'/exit.ready';
        $hangTerm = $dir.'/hang.term';
        $exitTerm = $dir.'/exit.term';

        $scriptBody = <<<'PHP'
<?php
declare(strict_types=1);
pcntl_async_signals(true);
$dir = __DIR__;
$role = \in_array('term_hang', $argv, true) ? 'hang' : 'exit';
// Install handler before ready so phase-1 SIGTERM cannot race setup.
pcntl_signal(\SIGTERM, static function () use ($role, $dir): void {
    $path = $dir.'/'.$role.'.term';
    // First TERM only — stop(0) may re-signal survivors and would overwrite timestamps.
    if (!is_file($path)) {
        file_put_contents($path, (string) microtime(true));
    }
    if ('exit' === $role) {
        exit(0);
    }
    // hang role intentionally stays alive after TERM so stop(0) escalation runs.
});
file_put_contents($dir.'/'.$role.'.ready', (string) microtime(true), \LOCK_EX);
while (true) {
    usleep(50_000);
}
PHP;
        file_put_contents($script, $scriptBody);

        $supervisor = null;
        try {
            $this->logger = new TestLogger();
            $locator = $this->createStub(AppExecutableLocator::class);
            $locator->method('path')->willReturn($script);
            $locator->method('command')->willReturn([\PHP_BINARY, $script]);
            $config = new RuntimeProcessConfig($locator, $dir);
            // 1s shared grace: sequential stop would push second TERM >=1s later.
            $supervisor = new ConsumerSupervisor($this->logger, $config, shutdownGraceSeconds: 1);

            $supervisor->launch('term_hang', 0);
            $supervisor->launch('term_exit', 0);

            $this->waitForConsumerReadyMarkers(
                $supervisor,
                [
                    'term_hang#0' => $hangReady,
                    'term_exit#0' => $exitReady,
                ],
            );

            $supervisor->shutdown();

            $this->assertFileExists($hangTerm, 'hang consumer must observe SIGTERM');
            $this->assertFileExists($exitTerm, 'exit consumer must observe SIGTERM');

            $hangAt = (float) file_get_contents($hangTerm);
            $exitAt = (float) file_get_contents($exitTerm);
            $delta = abs($hangAt - $exitAt);
            $this->assertLessThan(
                0.5,
                $delta,
                \sprintf(
                    'Both consumers must receive SIGTERM near-simultaneously under shared grace; delta=%.3fs (sequential stop delays second by full grace)',
                    $delta,
                ),
            );

            $running = $this->consumerKeysRunning($supervisor);
            $this->assertSame([], $running, 'shutdown must clear tracked consumers');
        } finally {
            if (null !== $supervisor) {
                // shutdown() already cleared map on success; force-clear survivors on failure.
                $ref = new \ReflectionClass($supervisor);
                $prop = $ref->getProperty('consumers');
                /** @var array<string, Process> $consumers */
                $consumers = $prop->getValue($supervisor);
                foreach ($consumers as $process) {
                    if ($process->isRunning()) {
                        $process->stop(0);
                    }
                }
                $prop->setValue($supervisor, []);
            }

            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    public function testLaunchUsesMemoryLimitNotTimeLimit(): void
    {
        $argvFile = tempnam(sys_get_temp_dir(), 'hatfield-consumer-argv-');
        $this->assertNotFalse($argvFile);

        try {
            $supervisor = $this->createSupervisor($argvFile);
            $supervisor->launch('test_transport', 0);

            $process = $this->getConsumerProcess($supervisor, 'test_transport#0');
            $process->wait();

            $argv = json_decode((string) file_get_contents($argvFile), true, 512, \JSON_THROW_ON_ERROR);
            $this->assertIsArray($argv);
            $this->assertContains('--memory-limit=256M', $argv);
            $this->assertContains('--keepalive=5', $argv);
            $this->assertContains('--sleep=0.01', $argv);
            $this->assertNotContains('--time-limit=3600', $argv);
            $this->assertContains('messenger:consume', $argv);
            $this->assertContains('test_transport', $argv);
        } finally {
            @unlink($argvFile);
        }
    }

    public function testGracefulExitCodeZeroRecyclesImmediatelyWithoutAbandonment(): void
    {
        $argvFile = tempnam(sys_get_temp_dir(), 'hatfield-consumer-argv-');
        $this->assertNotFalse($argvFile);

        try {
            $supervisor = $this->createSupervisor($argvFile);
            $abandoned = false;
            $supervisor->onConsumerAbandoned(static function () use (&$abandoned): void {
                $abandoned = true;
            });

            $supervisor->launch('grace_transport', 2);
            $first = $this->getConsumerProcess($supervisor, 'grace_transport#2');
            $firstPid = $first->getPid();
            $first->wait();

            $supervisor->supervise();

            $this->assertFalse($abandoned, 'exit code 0 must not invoke abandonment callback');

            $running = $this->consumerKeysRunning($supervisor);
            $this->assertArrayHasKey('grace_transport#2', $running);
            $this->assertTrue($running['grace_transport#2']);

            $warningMessages = array_values(array_filter(
                $this->logger->records,
                static fn (array $record): bool => 'warning' === $record['level']
                    && str_contains($record['message'], 'exited unexpectedly'),
            ));
            $this->assertSame([], $warningMessages);

            $recycleLogs = array_values(array_filter(
                $this->logger->records,
                static fn (array $record): bool => 'info' === $record['level']
                    && 'Consumer process exited gracefully, recycling' === $record['message'],
            ));
            $this->assertCount(1, $recycleLogs);
            $this->assertSame(0, $recycleLogs[0]['context']['exit_code']);

            $second = $this->getConsumerProcess($supervisor, 'grace_transport#2');
            $this->assertNotSame($firstPid, $second->getPid());
            $second->stop(0);
        } finally {
            @unlink($argvFile);
        }
    }

    public function testAbnormalExitUsesCrashRestartPath(): void
    {
        $argvFile = tempnam(sys_get_temp_dir(), 'hatfield-consumer-argv-');
        $this->assertNotFalse($argvFile);

        try {
            $supervisor = $this->createSupervisor($argvFile, exitCode: 2);
            $supervisor->launch('crash_transport', 0);
            $process = $this->getConsumerProcess($supervisor, 'crash_transport#0');
            $process->wait();

            $supervisor->supervise();

            $warnings = array_values(array_filter(
                $this->logger->records,
                static fn (array $record): bool => 'warning' === $record['level']
                    && 'Consumer process exited unexpectedly' === $record['message'],
            ));
            $this->assertCount(1, $warnings);
            $this->assertSame(2, $warnings[0]['context']['exit_code']);

            $restartLogs = array_values(array_filter(
                $this->logger->records,
                static fn (array $record): bool => 'info' === $record['level']
                    && 'Restarting consumer with backoff' === $record['message'],
            ));
            $this->assertCount(1, $restartLogs);
        } finally {
            @unlink($argvFile);
        }
    }

    public function testLaunchMultipleCreatesIndependentLlmInstances(): void
    {
        $argvFile = tempnam(sys_get_temp_dir(), 'hatfield-consumer-llm-pool-');
        $this->assertNotFalse($argvFile);

        try {
            $supervisor = $this->createSupervisor($argvFile);
            $supervisor->launchMultiple('llm', 4);

            $running = $this->consumerKeysRunning($supervisor);
            $keys = array_keys($running);
            sort($keys);
            $this->assertSame(['llm#0', 'llm#1', 'llm#2', 'llm#3'], $keys);

            foreach (['llm#0', 'llm#1', 'llm#2', 'llm#3'] as $key) {
                $process = $this->getConsumerProcess($supervisor, $key);
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
        } finally {
            @unlink($argvFile);
        }
    }

    private function createSupervisor(string $argvCaptureFile, int $exitCode = 0): ConsumerSupervisor
    {
        $this->logger = new TestLogger();
        $locator = $this->createStub(AppExecutableLocator::class);
        $script = $this->createArgvCaptureScript($argvCaptureFile, $exitCode);
        $locator->method('path')->willReturn($script);
        $locator->method('command')->willReturn(['php', $script]);
        $config = new RuntimeProcessConfig($locator, sys_get_temp_dir());

        return new ConsumerSupervisor($this->logger, $config);
    }

    private function createArgvCaptureScript(string $argvCaptureFile, int $exitCode): string
    {
        $script = tempnam(sys_get_temp_dir(), 'hatfield-consumer-launcher-');
        $this->assertNotFalse($script);

        $payload = <<<'PHP'
<?php
file_put_contents(%s, json_encode($argv, JSON_THROW_ON_ERROR));
exit(%d);
PHP;

        file_put_contents($script, \sprintf($payload, var_export($argvCaptureFile, true), $exitCode));

        return $script;
    }

    /**
     * @return array<string, bool>
     */
    private function consumerKeysRunning(ConsumerSupervisor $supervisor): array
    {
        $ref = new \ReflectionClass($supervisor);
        $prop = $ref->getProperty('consumers');
        /** @var array<string, Process> $consumers */
        $consumers = $prop->getValue($supervisor);
        $running = [];
        foreach ($consumers as $key => $process) {
            $running[$key] = $process->isRunning();
        }

        return $running;
    }

    private function getConsumerProcess(ConsumerSupervisor $supervisor, string $key): Process
    {
        $ref = new \ReflectionClass($supervisor);
        $prop = $ref->getProperty('consumers');
        /** @var array<string, Process> $consumers */
        $consumers = $prop->getValue($supervisor);
        $this->assertArrayHasKey($key, $consumers);

        return $consumers[$key];
    }

    /**
     * Wait for child-owned ready markers using process liveness, not a fixed wall sleep.
     *
     * @param array<string, string> $readyByConsumerKey
     */
    private function waitForConsumerReadyMarkers(ConsumerSupervisor $supervisor, array $readyByConsumerKey): void
    {
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $missing = [];
            foreach ($readyByConsumerKey as $key => $readyPath) {
                if (is_file($readyPath)) {
                    continue;
                }

                $process = $this->getConsumerProcess($supervisor, $key);
                if (!$process->isRunning()) {
                    $this->fail(\sprintf(
                        'Consumer %s exited before ready marker %s (exit=%s stderr=%s stdout=%s)',
                        $key,
                        $readyPath,
                        (string) $process->getExitCode(),
                        trim($process->getErrorOutput()),
                        trim($process->getOutput()),
                    ));
                }
                $missing[] = $key;
            }

            if ([] === $missing) {
                return;
            }

            usleep(5_000);
        }

        $details = [];
        foreach ($readyByConsumerKey as $key => $readyPath) {
            $process = $this->getConsumerProcess($supervisor, $key);
            $details[] = \sprintf(
                '%s ready=%s running=%s exit=%s stderr=%s',
                $key,
                is_file($readyPath) ? 'yes' : 'no',
                $process->isRunning() ? 'yes' : 'no',
                (string) $process->getExitCode(),
                trim($process->getErrorOutput()),
            );
        }

        $this->fail('Consumers failed to publish ready markers: '.implode('; ', $details));
    }
}
