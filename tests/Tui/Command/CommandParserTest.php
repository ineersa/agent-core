<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Command;

use Ineersa\Tui\Command\CommandParser;
use Ineersa\Tui\Command\NormalPromptCommand;
use Ineersa\Tui\Command\ShellCommand;
use Ineersa\Tui\Command\SlashCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommandParser::class)]
final class CommandParserTest extends TestCase
{
    private CommandParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CommandParser();
    }

    // ─── NormalPrompt cases ────────────────────────────────────────────

    public function testEmptyStringReturnsNormalPrompt(): void
    {
        $result = $this->parser->parse('');

        self::assertInstanceOf(NormalPromptCommand::class, $result);
        self::assertSame('', $result->text);
    }

    public function testWhitespaceOnlyReturnsNormalPrompt(): void
    {
        $result = $this->parser->parse('   ');

        self::assertInstanceOf(NormalPromptCommand::class, $result);
        self::assertSame('', $result->text);
    }

    public function testNormalTextReturnsNormalPrompt(): void
    {
        $result = $this->parser->parse('hello world');

        self::assertInstanceOf(NormalPromptCommand::class, $result);
        self::assertSame('hello world', $result->text);
    }

    public function testTextWithSurroundingWhitespaceIsTrimmed(): void
    {
        $result = $this->parser->parse('  hello world  ');

        self::assertInstanceOf(NormalPromptCommand::class, $result);
        self::assertSame('hello world', $result->text);
    }

    // ─── SlashCommand cases ────────────────────────────────────────────

    public function testSlashHelpReturnsSlashCommand(): void
    {
        $result = $this->parser->parse('/help');

        self::assertInstanceOf(SlashCommand::class, $result);
        self::assertSame('help', $result->name);
        self::assertSame('', $result->args);
        self::assertSame('/help', $result->originalText);
    }

    public function testSlashHelpWithArgs(): void
    {
        $result = $this->parser->parse('/help me please');

        self::assertInstanceOf(SlashCommand::class, $result);
        self::assertSame('help', $result->name);
        self::assertSame('me please', $result->args);
        self::assertSame('/help me please', $result->originalText);
    }

    public function testSlashExit(): void
    {
        $result = $this->parser->parse('/exit');

        self::assertInstanceOf(SlashCommand::class, $result);
        self::assertSame('exit', $result->name);
        self::assertSame('', $result->args);
    }

    public function testSlashAloneReturnsNormalPrompt(): void
    {
        $result = $this->parser->parse('/');

        self::assertInstanceOf(NormalPromptCommand::class, $result);
        self::assertSame('/', $result->text);
    }

    public function testSlashSpaceReturnsNormalPrompt(): void
    {
        $result = $this->parser->parse('/ ');

        self::assertInstanceOf(NormalPromptCommand::class, $result);
        // After trim: "/ " → "/", which is a lone slash → NormalPrompt
        self::assertSame('/', $result->text);
    }

    public function testDoubleSlashEscapedReturnsNormalPrompt(): void
    {
        $result = $this->parser->parse('//escaped');

        self::assertInstanceOf(NormalPromptCommand::class, $result);
        self::assertSame('//escaped', $result->text);
    }

    public function testSlashCommandNameIsLowercased(): void
    {
        $result = $this->parser->parse('/HeLp');

        self::assertInstanceOf(SlashCommand::class, $result);
        self::assertSame('help', $result->name);
    }

    public function testSlashCommandWithLeadingWhitespace(): void
    {
        $result = $this->parser->parse('  /help  ');

        self::assertInstanceOf(SlashCommand::class, $result);
        self::assertSame('help', $result->name);
        self::assertSame('', $result->args);
        self::assertSame('/help', $result->originalText);
    }

    public function testSlashCommandArgsPreserveInternalWhitespace(): void
    {
        $result = $this->parser->parse('/search  foo   bar');

        self::assertInstanceOf(SlashCommand::class, $result);
        self::assertSame('search', $result->name);
        self::assertSame('foo   bar', $result->args);
    }

    public function testSlashCommandWithUnderscoreName(): void
    {
        $result = $this->parser->parse('/my_command arg');

        self::assertInstanceOf(SlashCommand::class, $result);
        self::assertSame('my_command', $result->name);
        self::assertSame('arg', $result->args);
    }

    public function testSlashCommandWithNumericName(): void
    {
        $result = $this->parser->parse('/123cmd');

        self::assertInstanceOf(SlashCommand::class, $result);
        self::assertSame('123cmd', $result->name);
    }

    // ─── ShellCommand cases ─────────────────────────────────────────────

    public function testShellVisibleCommand(): void
    {
        $result = $this->parser->parse('!ls -la');

        self::assertInstanceOf(ShellCommand::class, $result);
        self::assertSame('ls -la', $result->command);
        self::assertFalse($result->hidden);
        self::assertSame('!ls -la', $result->originalText);
    }

    public function testShellHiddenCommand(): void
    {
        $result = $this->parser->parse('!!secret cmd');

        self::assertInstanceOf(ShellCommand::class, $result);
        self::assertSame('secret cmd', $result->command);
        self::assertTrue($result->hidden);
        self::assertSame('!!secret cmd', $result->originalText);
    }

    public function testShellCommandWithLeadingWhitespace(): void
    {
        $result = $this->parser->parse('  !ls  ');

        self::assertInstanceOf(ShellCommand::class, $result);
        self::assertSame('ls', $result->command);
        self::assertFalse($result->hidden);
        self::assertSame('!ls', $result->originalText);
    }

    public function testShellHiddenCommandWithWhitespace(): void
    {
        $result = $this->parser->parse('  !!hidden  ');

        self::assertInstanceOf(ShellCommand::class, $result);
        self::assertSame('hidden', $result->command);
        self::assertTrue($result->hidden);
        self::assertSame('!!hidden', $result->originalText);
    }

    // ─── Multiline / edge cases ─────────────────────────────────────────

    public function testMultilineWithSlashInMiddleIsNormalPrompt(): void
    {
        $result = $this->parser->parse("hello\n/world");

        self::assertInstanceOf(NormalPromptCommand::class, $result);
    }

    public function testMultilineWithExclamationInMiddleIsNormalPrompt(): void
    {
        $result = $this->parser->parse("hello\n!world");

        self::assertInstanceOf(NormalPromptCommand::class, $result);
    }

    public function testOriginalTextPreservesTrimmedInput(): void
    {
        $result = $this->parser->parse('  some text  ');

        self::assertInstanceOf(NormalPromptCommand::class, $result);
        self::assertSame('some text', $result->text);
        self::assertSame('some text', $result->originalText());
    }
}
