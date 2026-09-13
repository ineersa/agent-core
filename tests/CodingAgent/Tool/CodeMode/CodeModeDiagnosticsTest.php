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
    public function testWarningsAreDedupedAndStrippedOfStacks(): void
    {
        $stdout = <<<'TXT'
Warning: Undefined variable $x in /tmp/hcmbshABC/script.php on line 7

Call Stack:
    0.0001     481000   1. {main}() /tmp/hcmbshABC/bootstrap.php:0
    0.0002     482000   2. include() /tmp/hcmbshABC/script.php:4
TXT;
        $stderr = <<<'TXT'
PHP Warning:  Undefined variable $x in /tmp/hcmbshABC/script.php on line 7
PHP Stack trace:
PHP   1. {main}() /tmp/hcmbshABC/bootstrap.php:0
PHP   2. include() /tmp/hcmbshABC/script.php:4
TXT;

        $prepared = CodeModeDiagnostics::prepare($stdout, $stderr);
        $block = CodeModeDiagnostics::renderBlock($prepared);

        $this->assertArrayHasKey('stdout', $prepared);
        $this->assertArrayNotHasKey('stderr', $prepared);
        $this->assertSame('Warning: Undefined variable $x in script.php(4)', $prepared['stdout']);
        $this->assertStringContainsString('Warning: Undefined variable $x in script.php(4)', $block);
        $this->assertStringNotContainsString('Stack', $block);
        $this->assertStringNotContainsString('/tmp/', $block);
        $this->assertSame(1, substr_count($block, 'Undefined variable $x'));
    }

    public function testErrorStacksKeepNormalizedPaths(): void
    {
        $stderr = <<<'TXT'
Fatal error: Allowed memory size of 268435456 bytes exhausted (tried to allocate 20480 bytes) in /tmp/hcmbshABC/script.php on line 8
Stack trace:
#0 /tmp/hcmbshABC/bootstrap.php(120): include()
#1 {main}
TXT;

        $prepared = CodeModeDiagnostics::prepare('', $stderr);
        $block = CodeModeDiagnostics::renderBlock($prepared);

        $this->assertStringContainsString('script.php(5)', $block);
        $this->assertStringContainsString('bootstrap.php(120)', $block);
        $this->assertStringContainsString('Stack trace', $block);
        $this->assertStringNotContainsString('/tmp/', $block);
    }

    public function testCombinedDiagnosticsAreHardBoundedWithMarker(): void
    {
        $stdout = str_repeat('A', 20_000);
        $prepared = CodeModeDiagnostics::prepare($stdout, null);
        $block = CodeModeDiagnostics::renderBlock($prepared);

        $this->assertLessThanOrEqual(CodeModeDiagnostics::MAX_BLOCK_CHARS, \strlen($block));
        $this->assertStringEndsWith(trim(CodeModeDiagnostics::TRUNCATION_MARKER), $block);
        $this->assertStringContainsString("stdout:\nAAAA", $block);
    }

    public function testUtf8TruncationDoesNotSplitMultibyteCharacters(): void
    {
        $stdout = str_repeat('é', 5000);
        $block = CodeModeDiagnostics::renderBlock(CodeModeDiagnostics::prepare($stdout, null));

        $this->assertLessThanOrEqual(CodeModeDiagnostics::MAX_BLOCK_CHARS, \strlen($block));
        $this->assertTrue(mb_check_encoding($block, 'UTF-8'));
        $this->assertStringEndsWith(trim(CodeModeDiagnostics::TRUNCATION_MARKER), $block);
    }

    public function testAppendToMessageBoundsExitPathDiagnostics(): void
    {
        $message = 'Code-mode PHP subprocess exited without returning a value (exit code 0).';
        $prepared = CodeModeDiagnostics::prepare(str_repeat('O', 20_000), null);
        $combined = CodeModeDiagnostics::appendToMessage($message, $prepared);

        $this->assertLessThanOrEqual(CodeModeDiagnostics::MAX_BLOCK_CHARS, \strlen($combined));
        $this->assertStringContainsString('exited without returning a value', $combined);
        $this->assertStringEndsWith(trim(CodeModeDiagnostics::TRUNCATION_MARKER), $combined);
    }
}
