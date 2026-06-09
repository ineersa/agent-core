<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Command;

use Ineersa\Tui\Command\ClearTranscript;
use Ineersa\Tui\Command\CommandParser;
use Ineersa\Tui\Command\DispatchShellCommand;
use Ineersa\Tui\Command\ExitApplication;
use Ineersa\Tui\Command\NormalPromptCommand;
use Ineersa\Tui\Command\ShellCommand;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\SubmissionRouter;
use Ineersa\Tui\Command\TranscriptMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SubmissionRouter::class)]
final class SubmissionRouterTest extends TestCase
{
    private SubmissionRouter $router;

    protected function setUp(): void
    {
        $this->router = new SubmissionRouter(
            new CommandParser(),
            new SlashCommandRegistry(),
        );
    }

    // ─── Normal prompts → null (send to runtime) ─────────────────────

    #[Test]
    public function normalTextReturnsNull(): void
    {
        $result = $this->router->route('hello world');

        self::assertNull($result);
    }

    #[Test]
    public function emptyTextReturnsNull(): void
    {
        $result = $this->router->route('');

        self::assertNull($result);
    }

    #[Test]
    public function whitespaceOnlyReturnsNull(): void
    {
        $result = $this->router->route('   ');

        self::assertNull($result);
    }

    #[Test]
    public function escapedSlashReturnsNull(): void
    {
        // "//something" is an escaped slash — not a command
        $result = $this->router->route('//not-a-command');

        self::assertNull($result);
    }

    // ─── Slash commands → CommandResult ─────────────────────────────

    #[Test]
    public function helpCommandReturnsTranscriptMessage(): void
    {
        $result = $this->router->route('/help');

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Available commands', $result->text);
        self::assertSame('system', $result->role);
    }

    #[Test]
    public function helpWithArgsReturnsDetailedHelp(): void
    {
        $result = $this->router->route('/help clear');

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Command: /clear', $result->text);
    }

    #[Test]
    public function helpAliasHReturnsHelp(): void
    {
        $result = $this->router->route('/h');

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Available commands', $result->text);
    }

    #[Test]
    public function clearCommandReturnsClearTranscript(): void
    {
        $result = $this->router->route('/clear');

        self::assertInstanceOf(ClearTranscript::class, $result);
    }

    #[Test]
    public function clearAliasClsReturnsClearTranscript(): void
    {
        $result = $this->router->route('/cls');

        self::assertInstanceOf(ClearTranscript::class, $result);
    }

    #[Test]
    public function exitCommandReturnsExitApplication(): void
    {
        $result = $this->router->route('/exit');

        self::assertInstanceOf(ExitApplication::class, $result);
    }

    #[Test]
    public function exitAliasQuitReturnsExitApplication(): void
    {
        $result = $this->router->route('/quit');

        self::assertInstanceOf(ExitApplication::class, $result);
    }

    #[Test]
    public function exitAliasQReturnsExitApplication(): void
    {
        $result = $this->router->route('/q');

        self::assertInstanceOf(ExitApplication::class, $result);
    }

    #[Test]
    public function unknownSlashCommandReturnsTranscriptMessage(): void
    {
        $result = $this->router->route('/nonexistent');

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Unknown command', $result->text);
        self::assertStringContainsString('/nonexistent', $result->text);
        self::assertSame('muted', $result->style);
    }

    #[Test]
    public function slashCommandWithArgsIsRouted(): void
    {
        // /exit --force → still routes to exit handler
        $result = $this->router->route('/exit --force');

        self::assertInstanceOf(ExitApplication::class, $result);
    }

    // ─── Shell commands ────────────────────────────────────────────

    #[Test]
    public function shellExclamationDispatchesShellCommand(): void
    {
        $result = $this->router->route('!echo hello');

        self::assertInstanceOf(DispatchShellCommand::class, $result);
        self::assertSame('echo hello', $result->command);
        self::assertSame('!echo hello', $result->originalText);
    }

    #[Test]
    public function shellDoubleExclamationReturnsUnsupportedMessage(): void
    {
        $result = $this->router->route('!!secret cmd');

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('not supported', $result->text);
        self::assertSame('muted', $result->style);
    }

    #[Test]
    public function shellEmptyCommandReturnsValidationMessage(): void
    {
        $result = $this->router->route('!');

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('empty', $result->text);
        self::assertSame('muted', $result->style);
    }

    #[Test]
    public function shellWhitespaceOnlyCommandReturnsValidationMessage(): void
    {
        $result = $this->router->route('!   ');

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('empty', $result->text);
        self::assertSame('muted', $result->style);
    }

    // ─── Trailing whitespace handling ────────────────────────────────

    #[Test]
    public function slashCommandWithTrailingWhitespaceStillRouted(): void
    {
        $result = $this->router->route('/help   ');

        self::assertInstanceOf(TranscriptMessage::class, $result);
    }

    #[Test]
    public function normalTextWithTrailingWhitespaceReturnsNull(): void
    {
        $result = $this->router->route('hello   ');

        self::assertNull($result);
    }
}
