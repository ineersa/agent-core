<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\CodingAgent\Runtime\Controller\ConsumerSupervisor;
use Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Controller\ConsumerSupervisor
 */
final class ConsumerSupervisorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('consumer-supervisor');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testClearsSymfonyStdoutAfterIncrementalRead(): void
    {
        $supervisor = $this->makeSupervisor();
        $process = new Process(
            ['bash', '-c', 'printf "evt\n"; sleep 1'],
            cwd: $this->tmpDir,
            timeout: null,
        );
        $process->start();
        $this->injectProcess($supervisor, 'tool#0', $process);

        $chunks = $this->collectStdoutChunks($supervisor, 'tool#0', $process);

        $this->assertSame("evt\n", $chunks);
        $this->assertSame('', $process->getOutput());

        $process->wait();
    }

    public function testStderrTailIsBoundedAndProcessErrorBufferIsCleared(): void
    {
        $supervisor = $this->makeSupervisor();
        $process = new Process(
            ['bash', '-c', 'head -c 20000 < /dev/zero | tr "\0" "e" >&2; printf "ok\n"; sleep 1'],
            cwd: $this->tmpDir,
            timeout: null,
        );
        $process->start();
        $this->injectProcess($supervisor, 'llm#0', $process);

        $this->pollUntilStderrTail($supervisor, 'llm#0', $process);

        $tail = $supervisor->stderrTailFor('llm#0');
        $this->assertLessThanOrEqual(16_384, strlen($tail));
        $this->assertStringEndsWith('e', $tail);

        $process->wait();
    }

    private function collectStdoutChunks(ConsumerSupervisor $supervisor, string $key, Process $process): string
    {
        $buffer = '';
        for ($i = 0; $i < 500 && $process->isRunning(); ++$i) {
            foreach ($supervisor->readIncrementalStdoutByConsumer() as $consumerKey => $chunk) {
                if ($consumerKey === $key) {
                    $buffer .= $chunk;
                }
            }
            if ('' !== $buffer) {
                break;
            }
        }

        return $buffer;
    }

    private function pollUntilStderrTail(ConsumerSupervisor $supervisor, string $key, Process $process): void
    {
        for ($i = 0; $i < 500 && $process->isRunning(); ++$i) {
            iterator_to_array($supervisor->readIncrementalStdoutByConsumer());
            if ('' !== $supervisor->stderrTailFor($key)) {
                return;
            }
        }

        $this->fail('Timed out waiting for stderr tail');
    }

    private function makeSupervisor(): ConsumerSupervisor
    {
        $locator = new class($this->tmpDir.'/bin/console') implements AppExecutableLocator {
            public function __construct(private string $path) {}
            public function path(): string { return $this->path; }
            /** @return list<string> */
            public function command(): array { return [PHP_BINARY, $this->path]; }
        };

        return new ConsumerSupervisor(
            new NullLogger(),
            new RuntimeProcessConfig($locator, $this->tmpDir),
        );
    }

    private function injectProcess(ConsumerSupervisor $supervisor, string $key, Process $process): void
    {
        $ref = new \ReflectionProperty(ConsumerSupervisor::class, 'consumers');
        $ref->setAccessible(true);
        $ref->setValue($supervisor, [$key => $process]);
    }
}
