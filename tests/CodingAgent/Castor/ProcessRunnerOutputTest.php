<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Castor;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use PHPUnit\Framework\TestCase;

final class ProcessRunnerOutputTest extends TestCase
{
    public function testPreservesLargeUtf8ProcessOutput(): void
    {
        self::requireCastorProcessRunner();

        $expected = str_repeat('─', 32768);
        $code = 'fwrite(STDOUT, str_repeat("\\xE2\\x94\\x80", 32768));';
        $result = run_commands_parallel([
            'utf8-output' => ['cmd' => escapeshellarg(\PHP_BINARY).' -r '.escapeshellarg($code)],
        ], ['utf8-output' => 5])['utf8-output'];

        $this->assertSame(0, $result['exitCode']);
        $this->assertSame($expected, $result['output']);
        $this->assertTrue(mb_check_encoding($result['output'], 'UTF-8'));
    }

    public function testDrainsAllCurrentlyBufferedBytes(): void
    {
        self::requireCastorProcessRunner();

        $pipes = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $this->assertIsArray($pipes);
        [$reader, $writer] = $pipes;

        try {
            stream_set_blocking($reader, false);
            $expected = 'tail─';
            fwrite($writer, $expected);

            $this->assertSame($expected, drain_available_process_output($reader));
        } finally {
            fclose($reader);
            fclose($writer);
        }
    }

    public function testReplacesMalformedBytesBeforeReturningCapturedOutput(): void
    {
        self::requireCastorProcessRunner();

        $code = 'fwrite(STDOUT, "prefix\\xFFsuffix");';
        $result = run_commands_parallel([
            'malformed-output' => ['cmd' => escapeshellarg(\PHP_BINARY).' -r '.escapeshellarg($code)],
        ], ['malformed-output' => 5])['malformed-output'];

        $this->assertSame(0, $result['exitCode']);
        $this->assertTrue(mb_check_encoding($result['output'], 'UTF-8'));
        $this->assertStringStartsWith('prefix', $result['output']);
        $this->assertStringContainsString('suffix', $result['output']);
        $this->assertStringEndsWith(
            '[Castor replaced malformed UTF-8 bytes in captured process output]',
            $result['output'],
        );
    }

    private static function requireCastorProcessRunner(): void
    {
        $processPhp = ProjectDir::get().'/.castor/process.php';
        self::assertFileExists($processPhp);
        require_once $processPhp;
        self::assertTrue(\function_exists('run_commands_parallel'));
    }
}
