<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Support;

use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Deterministic in-process TUI harness for layout/render assertions.
 *
 * Mounts {@see ChatScreen} on Symfony {@see VirtualTerminal} and exposes plain
 * screen text via {@see ScreenBuffer}. Does not start {@see Tui::run()}, so
 * keyboard input simulation is out of scope here (add in a later phase with
 * an explicit input-loop test if needed).
 */
final class VirtualTuiHarness
{
    private readonly VirtualTerminal $terminal;
    private readonly Tui $tui;
    private readonly ChatScreen $screen;

    public function __construct(
        int $columns = 120,
        int $rows = 40,
        string $sessionId = 'virtual-startup-session',
    ) {
        $this->terminal = new VirtualTerminal(columns: $columns, rows: $rows);
        $theme = new DefaultTheme(new ThemePalette('default'));
        $this->screen = new ChatScreen(
            theme: $theme,
            sessionId: $sessionId,
            promptEditor: new PromptEditor(),
        );
        $this->tui = new Tui(terminal: $this->terminal);
        $this->screen->mount($this->tui);
    }

    public function screen(): ChatScreen
    {
        return $this->screen;
    }

    public function tui(): Tui
    {
        return $this->tui;
    }

    public function terminal(): VirtualTerminal
    {
        return $this->terminal;
    }

    public function render(): void
    {
        $this->tui->requestRender(force: true);
        $this->tui->processRender();
    }

    public function plainScreenText(): string
    {
        $this->render();

        $buffer = new ScreenBuffer(
            width: $this->terminal->getColumns(),
            height: $this->terminal->getRows(),
        );
        $buffer->write($this->terminal->getOutput());

        return $buffer->getScreen();
    }

}
