<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\Tui\Command\CommandParser;
use Ineersa\Tui\Command\DispatchShellCommand;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\SubmissionRouter;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic keyboard input and local command-route proofs without tmux.
 *
 * Test thesis: virtual terminal input routes through Symfony TUI focus + EditorWidget
 * into PromptEditor text state; unsupported {@code !!} shell prefixes are rejected
 * by production {@see SubmissionRouter} and render on the virtual screen.
 */
final class TuiVirtualInputTest extends TestCase
{
    private const string SESSION_ID = 'virtual-input-session';

    private const string DOUBLE_BANG_UNSUPPORTED = '!! is not supported. Use ! to execute shell commands.';

    #[Test]
    public function testVirtualInputRoutesTypedTextToPromptEditor(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);

        try {
            $harness->startInputLoop();
            $harness->sendInput('hello virtual');

            self::assertSame('hello virtual', $harness->screen()->editorText());

            $screen = $harness->plainScreenText();
            self::assertStringContainsString('hello virtual', $screen, 'Typed text should appear on rendered screen');
        } finally {
            $harness->stopInputLoop();
        }
    }

    #[Test]
    public function testDoubleBangRejectionRoutesLocallyAndRendersOnVirtualScreen(): void
    {
        $submitted = '!!echo should-not-run';
        $router = new SubmissionRouter(new CommandParser(), new SlashCommandRegistry());

        $result = $router->route($submitted);

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertNotInstanceOf(DispatchShellCommand::class, $result);
        self::assertSame(self::DOUBLE_BANG_UNSUPPORTED, $result->text);
        self::assertSame('muted', $result->style);

        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $factory = new TranscriptBlockFactory();
        $block = $factory->system(
            runId: self::SESSION_ID,
            text: $result->text,
            seq: 1,
            style: $result->style,
        );

        $harness->screen()->setTranscriptBlocks([$block]);

        $screen = $harness->plainScreenText();
        self::assertStringContainsString(self::DOUBLE_BANG_UNSUPPORTED, $screen);
        self::assertStringContainsString('not supported', $screen);
    }
}
