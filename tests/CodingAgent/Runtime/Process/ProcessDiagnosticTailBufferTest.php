<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Process;

use Ineersa\CodingAgent\Runtime\Process\ProcessDiagnosticTailBuffer;
use PHPUnit\Framework\TestCase;

final class ProcessDiagnosticTailBufferTest extends TestCase
{
    public function testSmallDiagnosticBytesRemainUnchanged(): void
    {
        $this->assertSame("before\0after", ProcessDiagnosticTailBuffer::append("before\0", 'after'));
        $this->assertSame(str_repeat('x', 16_384), ProcessDiagnosticTailBuffer::append('', str_repeat('x', 16_384)));
    }

    public function testBudgetStartsOnCodepointBoundaryAndRetainsRawSplitEnd(): void
    {
        $emoji = '🙂';
        $tail = ProcessDiagnosticTailBuffer::append('', str_repeat('界', 6000).substr($emoji, 0, 2));
        $this->assertLessThanOrEqual(16_384, \strlen($tail));
        $this->assertStringStartsWith('界', $tail);
        $this->assertStringEndsWith(substr($emoji, 0, 2), $tail);
        $tail = ProcessDiagnosticTailBuffer::append($tail, substr($emoji, 2));
        $this->assertTrue(mb_check_encoding($tail, 'UTF-8'));
        $this->assertStringEndsWith($emoji, $tail);
        $this->assertLessThanOrEqual(16_384, \strlen($tail));
    }

    public function testLargeChunkDropsOldTailAndSkipsOnlyLeadingContinuationBytes(): void
    {
        $chunk = str_repeat('界', 100_000).'END';
        $tail = ProcessDiagnosticTailBuffer::append('old diagnostics', $chunk);
        $this->assertSame(str_repeat('界', intdiv(16_384 - 3, 3)).'END', $tail);
        $this->assertTrue(mb_check_encoding($tail, 'UTF-8'));
        $this->assertSame("\xF0\x9F", ProcessDiagnosticTailBuffer::append('', str_repeat("\x80", 16_384)."\xF0\x9F"));
    }
}
