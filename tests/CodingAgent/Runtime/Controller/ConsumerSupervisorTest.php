<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Runtime\Controller\ConsumerSupervisor;
use Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
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
        $this->logger = new TestLogger();
        $locator = $this->createStub(AppExecutableLocator::class);
        $config = new RuntimeProcessConfig($locator, __DIR__);
        $supervisor = new ConsumerSupervisor($this->logger, $config, shutdownGraceSeconds: 0);
        $calls = new ShutdownProcessCallLog();
        $hang = new RecordingShutdownProcess('hang', $calls, exitsOnTerm: false);
        $exit = new RecordingShutdownProcess('exit', $calls, exitsOnTerm: true);

        $ref = new \ReflectionClass($supervisor);
        $consumers = $ref->getProperty('consumers');
        $consumers->setValue($supervisor, [
            'term_hang#0' => $hang,
            'term_exit#0' => $exit,
        ]);

        $supervisor->shutdown();

        $this->assertSame([
            'running:hang',
            'signal:hang:'.\SIGTERM,
            'running:exit',
            'signal:exit:'.\SIGTERM,
            'running:hang',
            'stop:hang:0',
            'running:exit',
        ], $calls->entries);
        $this->assertFalse($hang->isMarkedRunning());
        $this->assertFalse($exit->isMarkedRunning());
        $this->assertSame([], $this->consumerKeysRunning($supervisor), 'shutdown must clear tracked consumers');
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
            $this->assertNotContains('--keepalive=5', $argv);
            $this->assertNotContains('--keepalive', $argv);
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
}

final class ShutdownProcessCallLog
{
    /** @var list<string> */
    public array $entries = [];
}

/** Deterministic process double for proving shutdown ordering without scheduler timing. */
final class RecordingShutdownProcess extends Process
{
    private bool $initialized = false;
    private bool $running = false;

    public function __construct(
        private readonly string $name,
        private readonly ShutdownProcessCallLog $calls,
        private readonly bool $exitsOnTerm,
    ) {
        parent::__construct([\PHP_BINARY, '-r', '']);
        $this->running = true;
        $this->initialized = true;
    }

    public function isRunning(): bool
    {
        if (!$this->initialized) {
            return false;
        }

        $this->calls->entries[] = 'running:'.$this->name;

        return $this->running;
    }

    public function signal(int $signal): static
    {
        $this->calls->entries[] = \sprintf('signal:%s:%d', $this->name, $signal);
        if ($this->exitsOnTerm && \SIGTERM === $signal) {
            $this->running = false;
        }

        return $this;
    }

    public function stop(float $timeout = 10, ?int $signal = null): int
    {
        $this->calls->entries[] = \sprintf('stop:%s:%g', $this->name, $timeout);
        $this->running = false;

        return 0;
    }

    public function getPid(): int
    {
        return 'hang' === $this->name ? 101 : 102;
    }

    public function isMarkedRunning(): bool
    {
        return $this->running;
    }
}
