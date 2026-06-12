<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\PromptTemplate\Tests;

use Ineersa\CodingAgent\PromptTemplate\PromptTemplateSubstitutor;
use PHPUnit\Framework\TestCase;

final class PromptTemplateSubstitutorTest extends TestCase
{
    private PromptTemplateSubstitutor $substitutor;

    protected function setUp(): void
    {
        $this->substitutor = new PromptTemplateSubstitutor();
    }

    // ─── $ARGUMENTS and $@ ───

    public function testArgumentsAndAtAreEquivalent(): void
    {
        $content = 'A: $ARGUMENTS B: $@';
        $result = $this->substitutor->substitute($content, ['foo', 'bar']);
        self::assertSame('A: foo bar B: foo bar', $result);
    }

    public function testNoRecursiveSubstitution(): void
    {
        // The argument value contains '$1' — it should NOT be scanned again.
        $content = '$1';
        $result = $this->substitutor->substitute($content, ['$2 literal']);
        self::assertSame('$2 literal', $result);
    }

    // ─── Positional placeholders ───

    public function testPositionalPlaceholders(): void
    {
        $content = '$1 $2 $3';
        $result = $this->substitutor->substitute($content, ['a', 'b', 'c']);
        self::assertSame('a b c', $result);
    }

    public function testPositionalPlaceholderOutOfRange(): void
    {
        self::assertSame('ok ', $this->substitutor->substitute('ok $5', ['a', 'b']));
    }

    public function testZeroDollar(): void
    {
        // $0 is replaced with empty string per plan spec (0 is not 1-indexed positional).
        self::assertSame('', $this->substitutor->substitute('$0', ['a', 'b']));
    }

    public function testDollarOneHundred(): void
    {
        // $100 maps to args[99] — out of range, becomes empty.
        self::assertSame('', $this->substitutor->substitute('$100', ['a']));
    }

    public function testDollarOneDotFive(): void
    {
        // $1.5 replaces $1 and leaves .5 literal.
        self::assertSame('hello.5', $this->substitutor->substitute('$1.5', ['hello']));
    }

    public function testMultipleOccurrences(): void
    {
        $content = '$1 and $1 again';
        $result = $this->substitutor->substitute($content, ['x']);
        self::assertSame('x and x again', $result);
    }

    public function testSpecialCharsAndUnicodeInArgs(): void
    {
        $content = '$@';
        $result = $this->substitutor->substitute($content, ['café', 'hello world', '!@#$']);
        self::assertSame('café hello world !@#$', $result);
    }

    public function testNewlinesInArgs(): void
    {
        $content = '$1';
        $result = $this->substitutor->substitute($content, ["line1\nline2"]);
        self::assertSame("line1\nline2", $result);
    }

    public function testArgumentCaseSensitivity(): void
    {
        $content = '$ARGUMENTS $arguments $Arguments';
        $result = $this->substitutor->substitute($content, ['x', 'y']);
        // Only the exact $ARGUMENTS is replaced.
        self::assertSame('x y $arguments $Arguments', $result);
    }

    public function testAtCaseSensitivity(): void
    {
        // $@ replaces, $@s (non-placeholder) should not be affected unless it matches $@ exactly
        // $@ is matched exactly as a string
        $result = $this->substitutor->substitute('$@', ['a', 'b']);
        self::assertSame('a b', $result);
    }

    public function testArgumentsExtraPrefix(): void
    {
        // $ARGUMENTS_EXTRA should replace the $ARGUMENTS prefix and leave _EXTRA
        $content = '$ARGUMENTS_EXTRA';
        $result = $this->substitutor->substitute($content, ['x', 'y']);
        self::assertSame('x y_EXTRA', $result);
    }

    public function testLongArgList(): void
    {
        $args = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k'];
        self::assertSame('b c', $this->substitutor->substitute('$2 $3', $args));
        self::assertSame('k', $this->substitutor->substitute('$11', $args));
    }

    public function testMultiDigitPlaceholders(): void
    {
        // $12 should match positional 12 (index 11).
        $args = range('a', 'z');
        self::assertSame('l', $this->substitutor->substitute('$12', $args));
    }

    public function testNoPlaceholdersPassthrough(): void
    {
        $content = 'just some text without placeholders';
        $result = $this->substitutor->substitute($content, ['a', 'b']);
        self::assertSame('just some text without placeholders', $result);
    }

    // ─── Slices ───

    public function testSliceFromN(): void
    {
        $content = '${@:2}';
        $result = $this->substitutor->substitute($content, ['a', 'b', 'c', 'd']);
        self::assertSame('b c d', $result);
    }

    public function testSliceFromNWithLength(): void
    {
        $content = '${@:2:2}';
        $result = $this->substitutor->substitute($content, ['a', 'b', 'c', 'd']);
        self::assertSame('b c', $result);
    }

    public function testSliceFromZero(): void
    {
        // ${@:0} clamps start to 1 (index 0).
        $content = '${@:0}';
        $result = $this->substitutor->substitute($content, ['a', 'b', 'c']);
        self::assertSame('a b c', $result);
    }

    public function testSliceOutOfRangeStart(): void
    {
        $content = '${@:100}';
        $result = $this->substitutor->substitute($content, ['a', 'b']);
        self::assertSame('', $result);
    }

    public function testSliceZeroLength(): void
    {
        $content = '${@:1:0}';
        $result = $this->substitutor->substitute($content, ['a', 'b', 'c']);
        self::assertSame('', $result);
    }

    public function testSliceLengthPastEnd(): void
    {
        $content = '${@:2:10}';
        $result = $this->substitutor->substitute($content, ['a', 'b', 'c']);
        self::assertSame('b c', $result);
    }

    public function testMixedPlaceholders(): void
    {
        $content = 'first: $1, rest: ${@:2}, all: $ARGUMENTS, also: $@';
        $result = $this->substitutor->substitute($content, ['one', 'two', 'three']);
        self::assertSame('first: one, rest: two three, all: one two three, also: one two three', $result);
    }
}
