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

        $this->assertInstanceOf(NormalPromptCommand::class, $result);
        $this->assertSame('', $result->text);
    }

    public function testWhitespaceOnlyReturnsNormalPrompt(): void
    {
        $result = $this->parser->parse('   ');

        $this->assertInstanceOf(NormalPromptCommand::class, $result);
        $this->assertSame('', $result->text);
    }

    public function testNormalTextReturnsNormalPrompt(): void
    {
        $result = $this->parser->parse('hello world');

        $this->assertInstanceOf(NormalPromptCommand::class, $result);
        $this->assertSame('hello world', $result->text);
    }

    public function testTextWithSurroundingWhitespaceIsTrimmed(): void
    {
        $result = $this->parser->parse('  hello world  ');

        $this->assertInstanceOf(NormalPromptCommand::class, $result);
        $this->assertSame('hello world', $result->text);
    }

    // ─── SlashCommand cases ────────────────────────────────────────────

    public function testSlashHelpReturnsSlashCommand(): void
    {
        $result = $this->parser->parse('/help');

        $this->assertInstanceOf(SlashCommand::class, $result);
        $this->assertSame('help', $result->name);
        $this->assertSame('', $result->args);
        $this->assertSame('/help', $result->originalText);
    }

    public function testSlashHelpWithArgs(): void
    {
        $result = $this->parser->parse('/help me please');

        $this->assertInstanceOf(SlashCommand::class, $result);
        $this->assertSame('help', $result->name);
        $this->assertSame('me please', $result->args);
        $this->assertSame('/help me please', $result->originalText);
    }

    public function testSlashExit(): void
    {
        $result = $this->parser->parse('/exit');

        $this->assertInstanceOf(SlashCommand::class, $result);
        $this->assertSame('exit', $result->name);
        $this->assertSame('', $result->args);
    }

    public function testSlashAloneReturnsNormalPrompt(): void
    {
        $result = $this->parser->parse('/');

        $this->assertInstanceOf(NormalPromptCommand::class, $result);
        $this->assertSame('/', $result->text);
    }

    public function testSlashSpaceReturnsNormalPrompt(): void
    {
        $result = $this->parser->parse('/ ');

        $this->assertInstanceOf(NormalPromptCommand::class, $result);
        // After trim: "/ " → "/", which is a lone slash → NormalPrompt
        $this->assertSame('/', $result->text);
    }

    public function testDoubleSlashEscapedReturnsNormalPrompt(): void
    {
        $result = $this->parser->parse('//escaped');

        $this->assertInstanceOf(NormalPromptCommand::class, $result);
        $this->assertSame('//escaped', $result->text);
    }

    public function testSlashCommandNameIsLowercased(): void
    {
        $result = $this->parser->parse('/HeLp');

        $this->assertInstanceOf(SlashCommand::class, $result);
        $this->assertSame('help', $result->name);
    }

    public function testSlashCommandWithLeadingWhitespace(): void
    {
        $result = $this->parser->parse('  /help  ');

        $this->assertInstanceOf(SlashCommand::class, $result);
        $this->assertSame('help', $result->name);
        $this->assertSame('', $result->args);
        $this->assertSame('/help', $result->originalText);
    }

    public function testSlashCommandArgsPreserveInternalWhitespace(): void
    {
        $result = $this->parser->parse('/search  foo   bar');

        $this->assertInstanceOf(SlashCommand::class, $result);
        $this->assertSame('search', $result->name);
        $this->assertSame('foo   bar', $result->args);
    }

    public function testSlashCommandWithUnderscoreName(): void
    {
        $result = $this->parser->parse('/my_command arg');

        $this->assertInstanceOf(SlashCommand::class, $result);
        $this->assertSame('my_command', $result->name);
        $this->assertSame('arg', $result->args);
    }

    public function testSlashCommandWithNumericName(): void
    {
        $result = $this->parser->parse('/123cmd');

        $this->assertInstanceOf(SlashCommand::class, $result);
        $this->assertSame('123cmd', $result->name);
    }

    // ─── ShellCommand cases ─────────────────────────────────────────────

    public function testShellSingleExclamation(): void
    {
        $result = $this->parser->parse('!ls -la');

        $this->assertInstanceOf(ShellCommand::class, $result);
        $this->assertSame('ls -la', $result->command);
        $this->assertSame('!ls -la', $result->originalText);
    }

    public function testShellDoubleExclamationStillParsedForRouting(): void
    {
        // Parser still recognizes !! so the router can produce a clear
        // unsupported-command message. Must not be silently executed.
        $result = $this->parser->parse('!!secret cmd');

        $this->assertInstanceOf(ShellCommand::class, $result);
        $this->assertSame('secret cmd', $result->command);
        $this->assertSame('!!secret cmd', $result->originalText);
    }

    public function testShellCommandWithLeadingWhitespace(): void
    {
        $result = $this->parser->parse('  !ls  ');

        $this->assertInstanceOf(ShellCommand::class, $result);
        $this->assertSame('ls', $result->command);
        $this->assertSame('!ls', $result->originalText);
    }

    public function testShellDoubleExclamationWithWhitespace(): void
    {
        $result = $this->parser->parse('  !!hidden  ');

        $this->assertInstanceOf(ShellCommand::class, $result);
        $this->assertSame('hidden', $result->command);
        $this->assertSame('!!hidden', $result->originalText);
    }

    // ─── Multiline / edge cases ─────────────────────────────────────────

    public function testMultilineWithSlashInMiddleIsNormalPrompt(): void
    {
        $result = $this->parser->parse("hello\n/world");

        $this->assertInstanceOf(NormalPromptCommand::class, $result);
    }

    public function testMultilineWithExclamationInMiddleIsNormalPrompt(): void
    {
        $result = $this->parser->parse("hello\n!world");

        $this->assertInstanceOf(NormalPromptCommand::class, $result);
    }

    public function testOriginalTextPreservesTrimmedInput(): void
    {
        $result = $this->parser->parse('  some text  ');

        $this->assertInstanceOf(NormalPromptCommand::class, $result);
        $this->assertSame('some text', $result->text);
        $this->assertSame('some text', $result->originalText());
    }
}
