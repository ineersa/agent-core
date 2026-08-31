<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\Tui\Command\CommandParser;
use Ineersa\Tui\Command\DispatchRuntime;
use Ineersa\Tui\Command\DispatchShellCommand;
use Ineersa\Tui\Command\Hotkey\HotkeyBindingDTO;
use Ineersa\Tui\Command\Hotkey\HotkeyRegistry;
use Ineersa\Tui\Command\Hotkey\HotkeyTableData;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\SubmissionRouter;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Listener\AppHotkeyRegistrar;
use Ineersa\Tui\Listener\EditorHotkeyRegistrar;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Transcript\HotkeyTableWidget;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use Ineersa\Tui\Transcript\TranscriptBlockWidgetFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;

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

            $this->assertSame('hello virtual', $harness->screen()->editorText());

            $screen = $harness->plainScreenText();
            $this->assertStringContainsString('hello virtual', $screen, 'Typed text should appear on rendered screen');
        } finally {
            $harness->stopInputLoop();
        }
    }

    #[Test]
    public function testDoubleBangRejectionRoutesLocallyAndRendersOnVirtualScreen(): void
    {
        $submitted = '!!echo should-not-run';
        $router = new SubmissionRouter(new CommandParser(), new SlashCommandRegistry(new SlashCommandCatalog()));

        $result = $router->route($submitted);

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertNotInstanceOf(DispatchShellCommand::class, $result);
        $this->assertSame(self::DOUBLE_BANG_UNSUPPORTED, $result->text);
        $this->assertSame('muted', $result->style);

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
        $this->assertStringContainsString(self::DOUBLE_BANG_UNSUPPORTED, $screen);
        $this->assertStringContainsString('not supported', $screen);
    }

    #[Test]
    public function testHotkeysSlashCommandRoutesLocallyAndRendersKeyboardShortcutsTable(): void
    {
        $hotkeyRegistry = new HotkeyRegistry();
        // Tall virtual screen so the full hotkeys catalog stays in the viewport.
        $harness = new VirtualTuiHarness(columns: 120, rows: 80, sessionId: self::SESSION_ID);
        $state = new TuiSessionState(self::SESSION_ID);

        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();

        (new AppHotkeyRegistrar($hotkeyRegistry))->register($context);
        (new EditorHotkeyRegistrar($harness->screen()->promptEditor(), $hotkeyRegistry))->register($context);

        $router = new SubmissionRouter(new CommandParser(), new SlashCommandRegistry(new SlashCommandCatalog($hotkeyRegistry)));
        $result = $router->route('/hotkeys');

        $this->assertInstanceOf(HotkeyTableData::class, $result);
        $this->assertNotInstanceOf(DispatchRuntime::class, $result);
        $this->assertNotSame([], $result->groups);

        $this->applyHotkeyTableToScreen($result, $state, $harness);

        $screen = $harness->plainScreenText();
        $this->assertStringContainsString('Keyboard shortcuts', $screen);

        foreach (['┌', '├', '└', '│', '┐', '┤', '┘'] as $boxChar) {
            $this->assertStringContainsString(
                $boxChar,
                $screen,
                \sprintf('/hotkeys output should contain box-drawing char "%s"', $boxChar),
            );
        }

        foreach (['Ctrl+C', 'Ctrl+D', 'Ctrl+O', 'Shift+Enter', 'Insert newline', 'Submit prompt', 'Enter', 'Tab'] as $entry) {
            $this->assertStringContainsString(
                $entry,
                $screen,
                \sprintf('/hotkeys output should contain "%s"', $entry),
            );
        }

        $last = $state->transcript[array_key_last($state->transcript)] ?? null;
        $this->assertNotNull($last);
        $this->assertInstanceOf(
            HotkeyTableWidget::class,
            (new TranscriptBlockWidgetFactory())->buildWidget($last, $harness->screen()->theme()),
            'Structured hotkey-table meta must produce HotkeyTableWidget via production factory',
        );
        // Mounted ChatScreen stylesheet applies theme tokens (ANSI) on the live path.
        $this->assertMatchesRegularExpression('/\e\[[0-9;]*m/', $harness->ansiOutput());
    }

    #[Test]
    public function testHotkeysTableReflowsAfterVirtualResize(): void
    {
        $hotkeyRegistry = new HotkeyRegistry();
        $harness = new VirtualTuiHarness(columns: 120, rows: 80, sessionId: self::SESSION_ID);
        $state = new TuiSessionState(self::SESSION_ID);

        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();

        (new AppHotkeyRegistrar($hotkeyRegistry))->register($context);
        (new EditorHotkeyRegistrar($harness->screen()->promptEditor(), $hotkeyRegistry))->register($context);

        $router = new SubmissionRouter(new CommandParser(), new SlashCommandRegistry(new SlashCommandCatalog($hotkeyRegistry)));
        $result = $router->route('/hotkeys');
        $this->assertInstanceOf(HotkeyTableData::class, $result);

        $this->applyHotkeyTableToScreen($result, $state, $harness);
        $wide = $harness->plainScreenText();
        $this->assertStringContainsString('Keyboard shortcuts', $wide);

        $harness->startInputLoop();
        try {
            // Keep height tall so the table stays in-viewport; only width reflows.
            $harness->terminal()->simulateResize(40, 80);
            $harness->render();
            $narrow = $harness->plainScreenText();
        } finally {
            $harness->stopInputLoop();
        }

        $this->assertStringContainsString('Keyboard shortcuts', $narrow);
        $this->assertStringContainsString('Ctrl+C', $narrow);
        $this->assertNotSame($wide, $narrow, 'Resize must reflow the mounted hotkey table');
        $this->assertTrue(
            str_contains($narrow, '…') || str_contains($narrow, 'Ct…') || \strlen($narrow) < \strlen($wide),
            'Narrow reflow should truncate keys/descriptions or produce shorter output',
        );

        foreach (explode("\n", $narrow) as $i => $line) {
            $this->assertLessThanOrEqual(
                40,
                AnsiUtils::visibleWidth($line),
                "mounted row {$i} exceeds narrow width after resize",
            );
        }
    }

    /**
     * Mirror {@see \Ineersa\Tui\Listener\SubmitListener} structured hotkey-table application.
     */
    private function applyHotkeyTableToScreen(
        HotkeyTableData $result,
        TuiSessionState $state,
        VirtualTuiHarness $harness,
    ): void {
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

        $factory = new TranscriptBlockFactory();
        $block = $factory->system(
            runId: $state->sessionId,
            text: '',
            seq: \count($state->transcript) + 1,
            style: 'hotkey-table',
        );
        $state->transcript[] = $block->with(meta: array_merge($block->meta, [
            'hotkey_groups' => $groups,
            'empty_message' => $result->emptyMessage,
        ]));
        $harness->screen()->setTranscriptBlocks($state->transcript);
    }
}
