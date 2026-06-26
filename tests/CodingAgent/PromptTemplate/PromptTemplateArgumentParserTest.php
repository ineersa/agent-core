<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\PromptTemplate\Tests;

use Ineersa\CodingAgent\PromptTemplate\PromptTemplateArgumentParser;
use PHPUnit\Framework\TestCase;

final class PromptTemplateArgumentParserTest extends TestCase
{
    private PromptTemplateArgumentParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PromptTemplateArgumentParser();
    }

    public function testSimpleArgs(): void
    {
        $this->assertSame(['foo', 'bar', 'baz'], $this->parser->parse('foo bar baz'));
    }

    public function testDoubleQuotedArg(): void
    {
        $this->assertSame(['hello world', 'foo'], $this->parser->parse('"hello world" foo'));
    }

    public function testSingleQuotedArg(): void
    {
        // Single quotes group whitespace; backslash has no special meaning.
        $this->assertSame(['hello world', 'yes'], $this->parser->parse("'hello world' yes"));
    }

    public function testMixedQuotes(): void
    {
        $this->assertSame(
            ['one', 'two three', 'four', 'five six'],
            $this->parser->parse("one \"two three\" four 'five six'"),
        );
    }

    public function testEmptyString(): void
    {
        $this->assertSame([], $this->parser->parse(''));
    }

    public function testExtraSpaces(): void
    {
        $this->assertSame(['a', 'b'], $this->parser->parse('  a   b  '));
    }

    public function testTabsAsSeparators(): void
    {
        $this->assertSame(['a', 'b', 'c'], $this->parser->parse("a\tb\tc"));
    }

    public function testNewlinesAsSeparators(): void
    {
        $this->assertSame(['a', 'b', 'c'], $this->parser->parse("a\nb\nc"));
    }

    public function testNewlinesInsideQuotesPreserved(): void
    {
        $this->assertSame(["line1\nline2"], $this->parser->parse("\"line1\nline2\""));
    }

    public function testEmptyQuotesSkipped(): void
    {
        $this->assertSame(['a', 'b'], $this->parser->parse('a "" b'));
        $this->assertSame(['a', 'b'], $this->parser->parse("a '' b"));
    }

    public function testSpecialCharacters(): void
    {
        $this->assertSame(['$ARGUMENTS', '$@', '${@:1}'], $this->parser->parse('$ARGUMENTS $@ ${@:1}'));
    }

    public function testUnicode(): void
    {
        $this->assertSame(['café', 'naïve', 'résumé'], $this->parser->parse('café naïve résumé'));
    }

    public function testBackslashLiteralNoEscaping(): void
    {
        // Backslash is literal — it does not escape quotes.
        // Input: \"still-quoted\" => the backslashes end up inside the quoted arg.
        // The opening " starts a quote after the first backslash is buffered,
        // then the closing " ends it after the second backslash.
        $expected = ['\\still-quoted\\'];
        $this->assertSame($expected, $this->parser->parse('\"still-quoted\"'));
    }

    public function testLeadingTrailingWhitespace(): void
    {
        $this->assertSame(['hello', 'world'], $this->parser->parse(" \t hello \n world \t "));
    }

    public function testUnclosedDoubleQuote(): void
    {
        // The rest of the string after the opening quote becomes one arg.
        $this->assertSame(['hello world'], $this->parser->parse('"hello world'));
    }

    public function testUnclosedSingleQuote(): void
    {
        $this->assertSame(['hello world'], $this->parser->parse("'hello world"));
    }
}
