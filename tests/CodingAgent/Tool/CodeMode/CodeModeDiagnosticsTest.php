<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

use Ineersa\CodingAgent\Tool\CodeMode\CodeModeDiagnostics;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Tool\CodeMode\CodeModeDiagnostics
 */
final class CodeModeDiagnosticsTest extends TestCase
{
    public function testNormalizePathsCoversCommonPhpShapes(): void
    {
        $text = implode("\n", [
            'TypeError: strlen(): Argument #1 ($string) must be of type string, array given in /tmp/hcmbshABC/script.php on line 8',
            'Parse error: syntax error, unexpected token ";" in /tmp/hcmbshABC/script.php(4)',
            'Fatal error in /tmp/hcmbshABC/bootstrap.php:120',
            '#0 /tmp/hcmbshABC/bootstrap.php(120): include()',
        ]);

        $normalized = CodeModeDiagnostics::normalizePaths($text);

        $this->assertStringContainsString('script.php(5)', $normalized);
        $this->assertStringContainsString('script.php(1)', $normalized);
        $this->assertStringContainsString('bootstrap.php(120)', $normalized);
        $this->assertStringNotContainsString('/tmp/', $normalized);
    }

    public function testRenderBlockBoundsHeaderPlusStreamTails(): void
    {
        // Measured case: host already keeps only the last 4000 bytes of each
        // stream. Headers still push the rendered block over 4000, so truncate.
        $stdout = str_repeat('A', 4000);
        $stderr = str_repeat('B', 4000);
        $block = CodeModeDiagnostics::renderBlock([
            'stdout' => $stdout,
            'stderr' => $stderr,
        ]);

        $this->assertSame(4000 + 4000, \strlen($stdout) + \strlen($stderr));
        $this->assertGreaterThan(CodeModeDiagnostics::MAX_BLOCK_CHARS, \strlen("code_mode diagnostics\nstdout:\n".$stdout."\n\nstderr:\n".$stderr));
        $this->assertLessThanOrEqual(CodeModeDiagnostics::MAX_BLOCK_CHARS, \strlen($block));
        $this->assertStringEndsWith(trim(CodeModeDiagnostics::TRUNCATION_MARKER), $block);
        $this->assertStringContainsString("stdout:\nAAAA", $block);
    }

    public function testUtf8TruncationDoesNotSplitMultibyteCharacters(): void
    {
        $stdout = str_repeat('é', 5000);
        $block = CodeModeDiagnostics::renderBlock(['stdout' => $stdout]);

        $this->assertLessThanOrEqual(CodeModeDiagnostics::MAX_BLOCK_CHARS, \strlen($block));
        $this->assertTrue(mb_check_encoding($block, 'UTF-8'));
        $this->assertStringEndsWith(trim(CodeModeDiagnostics::TRUNCATION_MARKER), $block);
    }

    public function testAppendToMessageBoundsExitPathDiagnostics(): void
    {
        $message = 'Code-mode PHP subprocess exited without returning a value (exit code 0).';
        $combined = CodeModeDiagnostics::appendToMessage($message, [
            'stdout' => str_repeat('O', 4000),
            'stderr' => str_repeat('E', 4000),
        ]);

        $this->assertLessThanOrEqual(CodeModeDiagnostics::MAX_BLOCK_CHARS, \strlen($combined));
        $this->assertStringContainsString('exited without returning a value', $combined);
        $this->assertStringEndsWith(trim(CodeModeDiagnostics::TRUNCATION_MARKER), $combined);
    }

    public function testPrepareDoesNotRewriteUserStdoutMatchingWarningText(): void
    {
        $stdout = "Warning: this is intentional script output\n";
        $stderr = "PHP Warning:  Undefined variable \$x in /tmp/hcmbshABC/script.php on line 7\n";

        $prepared = CodeModeDiagnostics::prepare($stdout, $stderr);

        $this->assertSame('Warning: this is intentional script output', $prepared['stdout'] ?? null);
        $this->assertStringContainsString('Undefined variable $x in script.php(4)', $prepared['stderr'] ?? '');
        $this->assertStringStartsWith('PHP Warning:', $prepared['stderr'] ?? '');
        $this->assertStringNotContainsString('/tmp/', $prepared['stderr'] ?? '');
    }
}
