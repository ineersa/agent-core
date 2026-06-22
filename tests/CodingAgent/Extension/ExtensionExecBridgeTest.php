<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\CodingAgent\Extension\ExtensionExecBridge;
use Ineersa\Hatfield\ExtensionApi\ExecOptionsDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ExtensionExecBridge — the Symfony Process-backed implementation
 * of ExecInterface exposed to extensions.
 *
 * Covers basic subprocess execution, timeout handling, and exit code capture.
 */
final class ExtensionExecBridgeTest extends TestCase
{
    private ExtensionExecBridge $execBridge;

    protected function setUp(): void
    {
        $this->execBridge = new ExtensionExecBridge();
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
        // Timeout is implemented by killing the process; exit code may vary
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
