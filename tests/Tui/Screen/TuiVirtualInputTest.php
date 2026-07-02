<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\Tui\Command\CommandParser;
use Ineersa\Tui\Command\DispatchRuntime;
use Ineersa\Tui\Command\DispatchShellCommand;
use Ineersa\Tui\Command\Hotkey\HotkeyBindingDTO;
use Ineersa\Tui\Command\Hotkey\HotkeyRegistry;
use Ineersa\Tui\Command\Hotkey\HotkeyTableData;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\SubmissionRouter;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Listener\AppHotkeyRegistrar;
use Ineersa\Tui\Listener\EditorHotkeyRegistrar;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Transcript\HotkeyTableRenderer;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic keyboard input and local command-route proofs without tmux.
 *
 * Test thesis: virtual terminal input routes through Symfony TUI focus + EditorWidget
 * into PromptEditor text state; unsupported {@code !!} shell prefixes and {@code /hotkeys}
 * local slash commands are handled by production routing/render paths on the virtual screen.
 */
final class TuiVirtualInputTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

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

    #[Test]
    public function testHotkeysSlashCommandRoutesLocallyAndRendersKeyboardShortcutsTable(): void
    {
        $hotkeyRegistry = new HotkeyRegistry();
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $state = new TuiSessionState(self::SESSION_ID);

        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();

        (new AppHotkeyRegistrar($hotkeyRegistry))->register($context);
        (new EditorHotkeyRegistrar($harness->screen()->promptEditor(), $hotkeyRegistry))->register($context);

        $router = new SubmissionRouter(new CommandParser(), new SlashCommandRegistry($hotkeyRegistry));
        $result = $router->route('/hotkeys');

        self::assertInstanceOf(HotkeyTableData::class, $result);
        self::assertNotInstanceOf(DispatchRuntime::class, $result);
        self::assertFalse($result->isEmpty());

        $styledText = $this->applyHotkeyTableToScreen($result, $state, $harness->screen());

        self::assertStringContainsString('Keyboard shortcuts', $styledText);

        foreach (['┌', '├', '└', '│', '┐', '┤', '┘'] as $boxChar) {
            self::assertStringContainsString(
                $boxChar,
                $styledText,
                \sprintf('/hotkeys output should contain box-drawing char "%s"', $boxChar),
            );
        }

        foreach (['Ctrl+C', 'Ctrl+D', 'Ctrl+O', 'Shift+Enter', 'Insert newline', 'Submit prompt', 'Enter', 'Tab'] as $entry) {
            self::assertStringContainsString(
                $entry,
                $styledText,
                \sprintf('/hotkeys output should contain "%s"', $entry),
            );
        }

        $screen = $harness->plainScreenText();
        foreach (['Tab', 'Enter', 'Ctrl+P', 'Shift+Tab'] as $visibleEntry) {
            self::assertStringContainsString(
                $visibleEntry,
                $screen,
                \sprintf('Virtual screen should surface hotkeys table entry "%s"', $visibleEntry),
            );
        }
    }

    /**
     * Mirror {@see \Ineersa\Tui\Listener\SubmitListener} hotkey-table application for virtual screen proof.
     */
    private function applyHotkeyTableToScreen(
        HotkeyTableData $result,
        TuiSessionState $state,
        ChatScreen $screen,
    ): string {
        $renderer = new HotkeyTableRenderer();
        $groups = [];
        foreach ($result->groups as $context => $bindings) {
            $groups[$context] = array_map(
                static fn (HotkeyBindingDTO $binding): array => [
                    'keys' => $binding->keys,
                    'action' => $binding->action,
                    'description' => $binding->description,
                ],
                $bindings,
            );
        }

        $styledText = $renderer->render(
            $groups,
            $screen->theme(),
            $result->emptyMessage,
        );

        $factory = new TranscriptBlockFactory();
        $state->transcript[] = $factory->system(
            runId: $state->sessionId,
            text: $styledText,
            seq: \count($state->transcript) + 1,
            style: 'hotkey-table',
        );
        $screen->setTranscriptBlocks($state->transcript);

        return $styledText;
    }
}
