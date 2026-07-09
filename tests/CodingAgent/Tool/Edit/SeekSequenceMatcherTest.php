<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\Edit;

use Ineersa\CodingAgent\Tool\Edit\SeekSequenceMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SeekSequenceMatcher::class)]
final class SeekSequenceMatcherTest extends TestCase
{
    private SeekSequenceMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new SeekSequenceMatcher();
    }

    public function testExactMatchFindsUniqueSequence(): void
    {
        $lines = ['alpha', 'beta', 'gamma'];
        $pattern = ['beta', 'gamma'];

        $this->assertSame(1, $this->matcher->seekSequence($lines, $pattern, 0, false));
        $this->assertSame(1, $this->matcher->findUniqueMatch($lines, $pattern, 0, false));
    }

    public function testTrimEndPassMatchesTrailingWhitespace(): void
    {
        $lines = ['value   ', 'next'];
        $pattern = ['value', 'next'];

        $this->assertSame(0, $this->matcher->seekSequence($lines, $pattern, 0, false));
        $this->assertSame(0, $this->matcher->findUniqueMatch($lines, ['value   '], 0, false));
    }

    public function testFullTrimPassMatchesSurroundingWhitespace(): void
    {
        $lines = ['  padded  ', 'inner'];
        $pattern = ['padded', 'inner'];

        $this->assertSame(0, $this->matcher->seekSequence($lines, $pattern, 0, false));
    }

    public function testUnicodeNormalizePassMatchesCurlyQuotesAndDashes(): void
    {
        $lines = ["\u{201C}hello\u{201D}", "dash\u{2013}here"];
        $pattern = ['"hello"', 'dash-here'];

        $this->assertSame(0, $this->matcher->seekSequence($lines, $pattern, 0, false));
        $this->assertSame(0, $this->matcher->findUniqueMatch($lines, $pattern, 0, false));
    }

    public function testEofModeAnchorsSearchToFileEnd(): void
    {
        $lines = ['noise', 'keep', 'tail'];
        $pattern = ['keep', 'tail'];

        $this->assertSame(1, $this->matcher->seekSequence($lines, $pattern, 0, true));
        $this->assertSame(1, $this->matcher->findUniqueMatch($lines, $pattern, 0, true));
    }

    public function testEofAmbiguityRejectsWhenPatternAlsoMatchesEarlier(): void
    {
        $lines = ['block', 'end', 'block', 'end'];
        $pattern = ['block', 'end'];

        $this->assertSame(2, $this->matcher->seekSequence($lines, $pattern, 0, true));
        $this->assertNull($this->matcher->findUniqueMatch($lines, $pattern, 0, true));
    }

    public function testFindUniqueMatchRejectsDuplicateForwardMatches(): void
    {
        $lines = ['dup', 'mid', 'dup', 'fin'];
        $pattern = ['dup'];

        $this->assertNull($this->matcher->findUniqueMatch($lines, $pattern, 0, false));
    }

    public function testEmptyPatternReturnsStartIndex(): void
    {
        $lines = ['a', 'b'];

        $this->assertSame(3, $this->matcher->findUniqueMatch($lines, [], 3, false));
    }
}
