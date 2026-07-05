<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Command;

use Ineersa\Tui\Command\ClearTranscript;
use Ineersa\Tui\Command\CommandParser;
use Ineersa\Tui\Command\DispatchShellCommand;
use Ineersa\Tui\Command\ExitApplication;
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

        $this->assertNull($result);
    }

    #[Test]
    public function emptyTextReturnsNull(): void
    {
        $result = $this->router->route('');

        $this->assertNull($result);
    }

    #[Test]
    public function whitespaceOnlyReturnsNull(): void
    {
        $result = $this->router->route('   ');

        $this->assertNull($result);
    }

    #[Test]
    public function escapedSlashReturnsNull(): void
    {
        // "//something" is an escaped slash — not a command
        $result = $this->router->route('//not-a-command');

        $this->assertNull($result);
    }

    // ─── Slash commands → CommandResult ─────────────────────────────

    #[Test]
    public function helpCommandReturnsTranscriptMessage(): void
    {
        $result = $this->router->route('/help');

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Available commands', $result->text);
        $this->assertSame('system', $result->role);
    }

    #[Test]
    public function helpWithArgsReturnsDetailedHelp(): void
    {
        $result = $this->router->route('/help clear');

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Command: /clear', $result->text);
    }

    #[Test]
    public function helpAliasHReturnsHelp(): void
    {
        $result = $this->router->route('/h');

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Available commands', $result->text);
    }

    #[Test]
    public function clearCommandReturnsClearTranscript(): void
    {
        $result = $this->router->route('/clear');

        $this->assertInstanceOf(ClearTranscript::class, $result);
    }

    #[Test]
    public function clearAliasClsReturnsClearTranscript(): void
    {
        $result = $this->router->route('/cls');

        $this->assertInstanceOf(ClearTranscript::class, $result);
    }

    #[Test]
    public function exitCommandReturnsExitApplication(): void
    {
        $result = $this->router->route('/exit');

        $this->assertInstanceOf(ExitApplication::class, $result);
    }

    #[Test]
    public function exitAliasQuitReturnsExitApplication(): void
    {
        $result = $this->router->route('/quit');

        $this->assertInstanceOf(ExitApplication::class, $result);
    }

    #[Test]
    public function exitAliasQReturnsExitApplication(): void
    {
        $result = $this->router->route('/q');

        $this->assertInstanceOf(ExitApplication::class, $result);
    }

    #[Test]
    public function unknownSlashCommandReturnsTranscriptMessage(): void
    {
        $result = $this->router->route('/nonexistent');

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Unknown command', $result->text);
        $this->assertStringContainsString('/nonexistent', $result->text);
        $this->assertSame('muted', $result->style);
    }

    #[Test]
    public function slashCommandWithArgsIsRouted(): void
    {
        // /exit --force → still routes to exit handler
        $result = $this->router->route('/exit --force');

        $this->assertInstanceOf(ExitApplication::class, $result);
    }

    // ─── Shell commands ────────────────────────────────────────────

    #[Test]
    public function shellExclamationDispatchesShellCommand(): void
    {
        $result = $this->router->route('!echo hello');

        $this->assertInstanceOf(DispatchShellCommand::class, $result);
        $this->assertSame('echo hello', $result->command);
        $this->assertSame('!echo hello', $result->originalText);
    }

    #[Test]
    public function shellDoubleExclamationReturnsUnsupportedMessage(): void
    {
        $result = $this->router->route('!!secret cmd');

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('not supported', $result->text);
        $this->assertSame('muted', $result->style);
    }

    #[Test]
    public function shellEmptyCommandReturnsValidationMessage(): void
    {
        $result = $this->router->route('!');

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('empty', $result->text);
        $this->assertSame('muted', $result->style);
    }

    #[Test]
    public function shellWhitespaceOnlyCommandReturnsValidationMessage(): void
    {
        $result = $this->router->route('!   ');

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('empty', $result->text);
        $this->assertSame('muted', $result->style);
    }

    // ─── Trailing whitespace handling ────────────────────────────────

    #[Test]
    public function slashCommandWithTrailingWhitespaceStillRouted(): void
    {
        $result = $this->router->route('/help   ');

        $this->assertInstanceOf(TranscriptMessage::class, $result);
    }

    #[Test]
    public function normalTextWithTrailingWhitespaceReturnsNull(): void
    {
        $result = $this->router->route('hello   ');

        $this->assertNull($result);
    }
}
