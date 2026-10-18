<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\CodingAgent\Runtime\Messenger\InternalSqliteWorkerBootstrap;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Messenger\InternalSqliteWorkerBootstrap
 */
final class InternalSqliteWorkerBootstrapTest extends TestCase
{
    public function testMatchesDetectsFlagAnywhereInArgv(): void
    {
        $this->assertTrue(InternalSqliteWorkerBootstrap::matches([
            '/tmp/hatfield',
            InternalSqliteWorkerBootstrap::FLAG,
        ]));
        $this->assertTrue(InternalSqliteWorkerBootstrap::matches([
            \PHP_BINARY,
            __DIR__.'/../../../../bin/console',
            InternalSqliteWorkerBootstrap::FLAG,
            'runner.php',
        ]));
        $this->assertFalse(InternalSqliteWorkerBootstrap::matches([
            \PHP_BINARY,
            __DIR__.'/../../../../bin/console',
            'agent',
        ]));
    }

    public function testBundledPathsResolveReadableAmpScripts(): void
    {
        $runner = InternalSqliteWorkerBootstrap::bundledProcessRunnerPath();
        $worker = InternalSqliteWorkerBootstrap::bundledSqliteWorkerPath();
        $this->assertFileExists($runner);
        $this->assertFileIsReadable($runner);
        $this->assertFileExists($worker);
        $this->assertFileIsReadable($worker);
        $this->assertStringEndsWith('/vendor/amphp/parallel/src/Context/Internal/process-runner.php', $runner);
        $this->assertStringEndsWith('/vendor/fabpot/amphp-sqlite3/src/Internal/worker.php', $worker);
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: string}>
     */
    public static function rejectedWorkerArgvProvider(): iterable
    {
        $runner = InternalSqliteWorkerBootstrap::bundledProcessRunnerPath();
        $worker = InternalSqliteWorkerBootstrap::bundledSqliteWorkerPath();
        $validTail = [$runner, 'unix:///tmp/amp.sock', '16', '5', $worker];

        yield 'missing -d tokens' => [
            [InternalSqliteWorkerBootstrap::FLAG, ...$validTail],
            'fixed ProcessContext -d options',
        ];
        yield 'extra -d token' => [
            [
                InternalSqliteWorkerBootstrap::FLAG,
                '-dhtml_errors=0',
                '-ddisplay_errors=0',
                '-dlog_errors=1',
                '-dmemory_limit=64M',
                ...$validTail,
            ],
            'unsupported -d option',
        ];
        yield 'too few ipc args' => [
            [
                InternalSqliteWorkerBootstrap::FLAG,
                '-dhtml_errors=0',
                '-ddisplay_errors=0',
                '-dlog_errors=1',
                $runner,
                'unix:///tmp/amp.sock',
                '16',
            ],
            'exactly ipc-uri, key-length, timeout, and worker script',
        ];
        yield 'invalid ipc uri' => [
            [
                InternalSqliteWorkerBootstrap::FLAG,
                '-dhtml_errors=0',
                '-ddisplay_errors=0',
                '-dlog_errors=1',
                $runner,
                'file:///tmp/amp.sock',
                '16',
                '5',
                $worker,
            ],
            'IPC URI is invalid',
        ];
        yield 'non-positive key length' => [
            [
                InternalSqliteWorkerBootstrap::FLAG,
                '-dhtml_errors=0',
                '-ddisplay_errors=0',
                '-dlog_errors=1',
                $runner,
                'unix:///tmp/amp.sock',
                '0',
                '5',
                $worker,
            ],
            'key length must be a positive integer',
        ];
        yield 'non-positive timeout' => [
            [
                InternalSqliteWorkerBootstrap::FLAG,
                '-dhtml_errors=0',
                '-ddisplay_errors=0',
                '-dlog_errors=1',
                $runner,
                'unix:///tmp/amp.sock',
                '16',
                '0',
                $worker,
            ],
            'timeout must be a positive integer',
        ];
    }

    /**
     * @param list<string> $ampArgv
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedWorkerArgvProvider')]
    public function testTryRunRejectsNonContractArgv(array $ampArgv, string $errorFragment): void
    {
        $process = new Process([
            \PHP_BINARY,
            __DIR__.'/../../../../bin/console',
            ...$ampArgv,
        ], null, null, null, 5.0);
        $process->run();
        $this->assertSame(255, $process->getExitCode(), $process->getErrorOutput()."\n".$process->getOutput());
        $this->assertStringContainsString($errorFragment, $process->getErrorOutput());
    }

    public function testTryRunIgnoresCallerSuppliedRunnerAndWorkerPaths(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('sqlite-worker-bootstrap');
        try {
            $fakeRunner = $dir.'/evil-runner.php';
            $fakeWorker = $dir.'/evil-worker.php';
            file_put_contents($fakeRunner, "<?php fwrite(STDERR, \"evil-runner\\n\"); exit(7);\n");
            file_put_contents($fakeWorker, "<?php fwrite(STDERR, \"evil-worker\\n\"); exit(8);\n");

            $process = new Process([
                \PHP_BINARY,
                __DIR__.'/../../../../bin/console',
                InternalSqliteWorkerBootstrap::FLAG,
                '-dhtml_errors=0',
                '-ddisplay_errors=0',
                '-dlog_errors=1',
                $fakeRunner,
                'unix://'.$dir.'/missing.sock',
                '16',
                '1',
                $fakeWorker,
            ], null, null, null, 5.0);
            $process->run();

            // Bundled Amp runner starts and fails on the missing IPC socket. The
            // caller-supplied runner/worker must never execute.
            $this->assertNotSame(7, $process->getExitCode(), $process->getErrorOutput());
            $this->assertNotSame(8, $process->getExitCode(), $process->getErrorOutput());
            $this->assertStringNotContainsString('evil-runner', $process->getErrorOutput());
            $this->assertStringNotContainsString('evil-worker', $process->getErrorOutput());
            $this->assertStringNotContainsString('evil-runner', $process->getOutput());
            $this->assertStringNotContainsString('evil-worker', $process->getOutput());
            $combined = $process->getErrorOutput()."\n".$process->getOutput();
            $this->assertTrue(
                str_contains($combined, 'Could not connect to IPC socket')
                || str_contains($combined, 'No socket path provided')
                || str_contains($combined, 'Starting the process failed')
                || 255 === $process->getExitCode(),
                $combined,
            );
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }
}
