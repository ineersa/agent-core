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
        $this->assertSame('A: foo bar B: foo bar', $result);
    }

    public function testNoRecursiveSubstitution(): void
    {
        // The argument value contains '$1' — it should NOT be scanned again.
        $content = '$1';
        $result = $this->substitutor->substitute($content, ['$2 literal']);
        $this->assertSame('$2 literal', $result);
    }

    // ─── Positional placeholders ───

    public function testPositionalPlaceholders(): void
    {
        $content = '$1 $2 $3';
        $result = $this->substitutor->substitute($content, ['a', 'b', 'c']);
        $this->assertSame('a b c', $result);
    }

    public function testPositionalPlaceholderOutOfRange(): void
    {
        $this->assertSame('ok ', $this->substitutor->substitute('ok $5', ['a', 'b']));
    }

    public function testZeroDollar(): void
    {
        // $0 is replaced with empty string per plan spec (0 is not 1-indexed positional).
        $this->assertSame('', $this->substitutor->substitute('$0', ['a', 'b']));
    }

    public function testDollarOneHundred(): void
    {
        // $100 maps to args[99] — out of range, becomes empty.
        $this->assertSame('', $this->substitutor->substitute('$100', ['a']));
    }

    public function testDollarOneDotFive(): void
    {
        // $1.5 replaces $1 and leaves .5 literal.
        $this->assertSame('hello.5', $this->substitutor->substitute('$1.5', ['hello']));
    }

    public function testMultipleOccurrences(): void
    {
        $content = '$1 and $1 again';
        $result = $this->substitutor->substitute($content, ['x']);
        $this->assertSame('x and x again', $result);
    }

    public function testSpecialCharsAndUnicodeInArgs(): void
    {
        $content = '$@';
        $result = $this->substitutor->substitute($content, ['café', 'hello world', '!@#$']);
        $this->assertSame('café hello world !@#$', $result);
    }

    public function testNewlinesInArgs(): void
    {
        $content = '$1';
        $result = $this->substitutor->substitute($content, ["line1\nline2"]);
        $this->assertSame("line1\nline2", $result);
    }

    public function testArgumentCaseSensitivity(): void
    {
        $content = '$ARGUMENTS $arguments $Arguments';
        $result = $this->substitutor->substitute($content, ['x', 'y']);
        // Only the exact $ARGUMENTS is replaced.
        $this->assertSame('x y $arguments $Arguments', $result);
    }

    public function testAtCaseSensitivity(): void
    {
        // $@ replaces, $@s (non-placeholder) should not be affected unless it matches $@ exactly
        // $@ is matched exactly as a string
        $result = $this->substitutor->substitute('$@', ['a', 'b']);
        $this->assertSame('a b', $result);
    }

    public function testAtInBracesIsNotSliceSyntax(): void
    {
        // ${@} without a colon and digit is NOT slice syntax.
        // The slice regex requires ${@:N} or ${@:N:L}. ${@}
        // passes through unchanged — $@ is not a substring
        // of ${@} because { sits between $ and @.
        $content = 'prefix ${@} suffix';
        $result = $this->substitutor->substitute($content, ['a', 'b']);
        $this->assertSame('prefix ${@} suffix', $result);
    }

    public function testArgumentsExtraPrefix(): void
    {
        // $ARGUMENTS_EXTRA should replace the $ARGUMENTS prefix and leave _EXTRA
        $content = '$ARGUMENTS_EXTRA';
        $result = $this->substitutor->substitute($content, ['x', 'y']);
        $this->assertSame('x y_EXTRA', $result);
    }

    public function testLongArgList(): void
    {
        $args = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k'];
        $this->assertSame('b c', $this->substitutor->substitute('$2 $3', $args));
        $this->assertSame('k', $this->substitutor->substitute('$11', $args));
    }

    public function testMultiDigitPlaceholders(): void
    {
        // $12 should match positional 12 (index 11).
        $args = range('a', 'z');
        $this->assertSame('l', $this->substitutor->substitute('$12', $args));
    }

    public function testNoPlaceholdersPassthrough(): void
    {
        $content = 'just some text without placeholders';
        $result = $this->substitutor->substitute($content, ['a', 'b']);
        $this->assertSame('just some text without placeholders', $result);
    }

    // ─── Slices ───

    public function testSliceFromN(): void
    {
        $content = '${@:2}';
        $result = $this->substitutor->substitute($content, ['a', 'b', 'c', 'd']);
        $this->assertSame('b c d', $result);
    }

    public function testSliceFromNWithLength(): void
    {
        $content = '${@:2:2}';
        $result = $this->substitutor->substitute($content, ['a', 'b', 'c', 'd']);
        $this->assertSame('b c', $result);
    }

    public function testSliceFromZero(): void
    {
        // ${@:0} clamps start to 1 (index 0).
        $content = '${@:0}';
        $result = $this->substitutor->substitute($content, ['a', 'b', 'c']);
        $this->assertSame('a b c', $result);
    }

    public function testSliceOutOfRangeStart(): void
    {
        $content = '${@:100}';
        $result = $this->substitutor->substitute($content, ['a', 'b']);
        $this->assertSame('', $result);
    }

    public function testSliceZeroLength(): void
    {
        $content = '${@:1:0}';
        $result = $this->substitutor->substitute($content, ['a', 'b', 'c']);
        $this->assertSame('', $result);
    }

    public function testSliceLengthPastEnd(): void
    {
        $content = '${@:2:10}';
        $result = $this->substitutor->substitute($content, ['a', 'b', 'c']);
        $this->assertSame('b c', $result);
    }

    public function testMixedPlaceholders(): void
    {
        $content = 'first: $1, rest: ${@:2}, all: $ARGUMENTS, also: $@';
        $result = $this->substitutor->substitute($content, ['one', 'two', 'three']);
        $this->assertSame('first: one, rest: two three, all: one two three, also: one two three', $result);
    }
}
