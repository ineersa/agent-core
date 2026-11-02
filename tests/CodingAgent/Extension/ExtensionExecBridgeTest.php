<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Amp\TimeoutCancellation;
use Ineersa\CodingAgent\Extension\ExtensionExecBridge;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCancellationTokenInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\Socket\listen;

/**
 * Tests for ExtensionExecBridge — the Symfony Process-backed implementation
 * of ExecInterface exposed to extensions.
 *
 * Covers basic subprocess execution, timeout handling, cooperative cancellation,
 * and exit code capture.
 */
final class ExtensionExecBridgeTest extends TestCase
{
    private ExtensionExecBridge $execBridge;

    protected function setUp(): void
    {
        $this->execBridge = new ExtensionExecBridge();
    }

    public static function cooperativeCases(): iterable
    {
        yield 'normal held command' => [false, false];
        yield 'termination grace' => [true, false];
        yield 'forced termination after grace' => [true, true];
    }

    #[DataProvider('cooperativeCases')]
    public function testHeldCommandAndTerminationGraceYield(bool $cancel, bool $force): void
    {
        if (!\function_exists('pcntl_signal')) {
            $this->markTestSkipped('Native TERM readiness requires pcntl.');
        }
        $server = listen('127.0.0.1:0');
        $token = new class implements ToolCancellationTokenInterface {
            public bool $cancelled = false;

            public function isCancellationRequested(): bool
            {
                return $this->cancelled;
            }
        };
        $code = <<<'PHP'
$c = stream_socket_client($argv[1]);
pcntl_sigprocmask(SIG_BLOCK, [SIGTERM]);
fwrite($c, "READY\n");
if ($argv[2] === 'cancel') {
    pcntl_sigwaitinfo([SIGTERM]);
    fwrite($c, "TERM\n");
}
fgets($c);
echo "released";
fwrite(STDERR, "captured-error");
exit(23);
PHP;
        $future = async(fn () => $this->execBridge->exec(\PHP_BINARY, ['-r', $code, 'tcp://'.$server->getAddress()->toString(), $cancel ? 'cancel' : 'normal'], new ExecOptionsDTO(cancellationToken: $token, timeout: 8)));
        $socket = null;
        try {
            $socket = $server->accept(new TimeoutCancellation(2));
            $this->assertNotNull($socket);
            $this->assertSame("READY\n", $socket->read(new TimeoutCancellation(2)));
            $this->assertFalse($future->isComplete());
            if ($cancel) {
                $token->cancelled = true;
                $this->assertSame("TERM\n", $socket->read(new TimeoutCancellation(2)));
                // Child has received TERM but still waits for release: the
                // sibling must run inside the bridge's termination grace.
                $this->assertFalse(async(static fn () => $future->isComplete())->await(new TimeoutCancellation(2)));
            }
            if (!$force) {
                $socket->write("release\n");
            }
            $result = $future->await(new TimeoutCancellation(7));
            $this->assertSame($cancel, $result->cancelled);
            $this->assertFalse($result->timedOut);
            if (!$force) {
                $this->assertSame('released', $result->stdout);
                $this->assertSame('captured-error', $result->stderr);
                $this->assertSame(23, $result->exitCode);
            } else {
                $this->assertNotSame(23, $result->exitCode);
            }
        } finally {
            $socket?->close();
            $server->close();
            $token->cancelled = true;
            $future->await(new TimeoutCancellation(7));
        }
    }

    public function testExecCapturesStdout(): void
    {
        $result = $this->execBridge->exec('echo', ['hello world']);

        $this->assertStringContainsString('hello world', $result->stdout);
        $this->assertSame('', $result->stderr);
        $this->assertSame(0, $result->exitCode);
        $this->assertFalse($result->timedOut);
    }

    public function testExecPrintsFormattedString(): void
    {
        $result = $this->execBridge->exec('printf', ['%s %d', 'value', 42]);

        $this->assertSame('value 42', $result->stdout);
        $this->assertSame(0, $result->exitCode);
        $this->assertFalse($result->timedOut);
    }

    public function testExecCapturesStderr(): void
    {
        // Redirect stdout to stderr via shell — find a cross-platform way
        $result = $this->execBridge->exec('sh', ['-c', 'echo error_msg >&2']);

        $this->assertStringContainsString('error_msg', $result->stderr);
        // Exit code 0 because sh succeeded even though content went to stderr
        $this->assertSame(0, $result->exitCode);
    }

    public function testExecNonZeroExitCode(): void
    {
        $result = $this->execBridge->exec('sh', ['-c', 'exit 42']);

        $this->assertSame(42, $result->exitCode);
    }

    public function testExecTimeout(): void
    {
        $result = $this->execBridge->exec(
            'sleep',
            ['10'],
            new ExecOptionsDTO(timeout: 0.1),
        );

        $this->assertTrue($result->timedOut);
        $this->assertFalse($result->cancelled);
        // Timeout is implemented by killing the process; exit code may vary
    }

    public function testExecCancellationStopsSleepingChildBeforeMarker(): void
    {
        // Thesis: without cooperative cancel in ExtensionExecBridge, Escape cannot stop
        // an extension-owned subprocess mid-run, and markers would still be written.
        $dir = TestDirectoryIsolation::createProjectTempDir('exec-cancel');
        $marker = $dir.'/marker.txt';

        try {
            $token = new class implements ToolCancellationTokenInterface {
                private int $checks = 0;

                public function isCancellationRequested(): bool
                {
                    // Flip after the process has had a chance to start.
                    return ++$this->checks >= 2;
                }
            };

            $started = hrtime(true);
            $result = $this->execBridge->exec(
                'sh',
                ['-c', 'sleep 5; echo done > '.escapeshellarg($marker)],
                new ExecOptionsDTO(cancellationToken: $token, timeout: 10.0),
            );
            $elapsedMs = (hrtime(true) - $started) / 1_000_000;

            $this->assertTrue($result->cancelled);
            $this->assertFalse($result->timedOut);
            $this->assertFileDoesNotExist($marker);
            $this->assertLessThan(4000, $elapsedMs, 'cancelled child must stop well before the 5s sleep completes');
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    public function testExecDeadlineStopsSleepingChildBeforeMarker(): void
    {
        // Thesis: deadline path must stop owned children and set timedOut distinctly from cancelled.
        $dir = TestDirectoryIsolation::createProjectTempDir('exec-deadline');
        $marker = $dir.'/marker.txt';

        try {
            $started = hrtime(true);
            $result = $this->execBridge->exec(
                'sh',
                ['-c', 'sleep 5; echo done > '.escapeshellarg($marker)],
                new ExecOptionsDTO(timeout: 0.15),
            );
            $elapsedMs = (hrtime(true) - $started) / 1_000_000;

            $this->assertTrue($result->timedOut);
            $this->assertFalse($result->cancelled);
            $this->assertFileDoesNotExist($marker);
            $this->assertLessThan(3000, $elapsedMs);
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    public function testExecWithCwd(): void
    {
        $result = $this->execBridge->exec(
            'pwd',
            [],
            new ExecOptionsDTO(cwd: '/tmp'),
        );

        $this->assertStringContainsString('/tmp', $result->stdout);
        $this->assertSame(0, $result->exitCode);
    }

    public function testExecWithEmptyArgsAndOptions(): void
    {
        $result = $this->execBridge->exec('true');

        $this->assertSame(0, $result->exitCode);
    }

    public function testExecWithEnv(): void
    {
        $result = $this->execBridge->exec(
            'sh',
            ['-c', 'echo $MY_VAR'],
            new ExecOptionsDTO(env: ['MY_VAR' => 'test_value']),
        );

        $this->assertStringContainsString('test_value', $result->stdout);
        $this->assertSame(0, $result->exitCode);
    }

    public function testExecDoesNotShellInterpolate(): void
    {
        // Passing "$HOME" as a positional argument to echo — it should be
        // treated as a literal string, not an expanded variable.
        $result = $this->execBridge->exec('echo', ['$HOME literal']);

        // The Process with array args does NOT shell-interpolate
        $this->assertStringContainsString('$HOME literal', $result->stdout);
    }

    public function testStartFailureReturnsStructuredResult(): void
    {
        // When proc_open fails (e.g. cwd is a file, not a directory),
        // ProcessStartFailedException (extends ProcessRuntimeException)
        // is caught and returns a structured ExecResultDTO rather than
        // propagating the exception.
        $file = sys_get_temp_dir().'/exec_test_cwd_file_'.bin2hex(random_bytes(4));
        touch($file);

        try {
            $result = $this->execBridge->exec(
                'true',
                [],
                new ExecOptionsDTO(cwd: $file),
            );

            $this->assertSame(-1, $result->exitCode);
            $this->assertFalse($result->timedOut);
            // The exception diagnostics should be in stderr
            $this->assertNotEmpty($result->stderr);
        } finally {
            @unlink($file);
        }
    }
}
