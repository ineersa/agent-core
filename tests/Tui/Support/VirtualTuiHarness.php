<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Support;

use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Runtime\TabDefinition;
use Ineersa\Tui\Runtime\TabService;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Deterministic in-process TUI harness for layout/render assertions.
 *
 * Mounts {@see ChatScreen} on Symfony {@see VirtualTerminal} and exposes plain
 * screen text via {@see ScreenBuffer}. Optional {@see startInputLoop()} wires
 * the same terminal input callback as {@see Tui::run()} without blocking Revolt.
 *
 * POC: supports {@see TabService} for multi-tab rendering tests.
 */
final class VirtualTuiHarness
{
    private readonly VirtualTerminal $terminal;
    private readonly Tui $tui;
    private readonly ChatScreen $screen;
    private readonly TabService $tabService;

    private bool $inputLoopStarted = false;

    public function __construct(
        int $columns = 120,
        int $rows = 40,
        string $sessionId = 'virtual-startup-session',
        ?ThemePalette $palette = null,
        ?TabService $tabService = null,
    ) {
        $this->terminal = new VirtualTerminal(columns: $columns, rows: $rows);
        $palette ??= self::defaultVirtualPalette();
        $theme = new DefaultTheme($palette);

        // Build optional TabService with a parent tab
        $this->tabService = $tabService ?? self::createParentTabService($sessionId);

        $this->screen = new ChatScreen(
            theme: $theme,
            sessionId: $sessionId,
            promptEditor: new PromptEditor(),
            tabService: $this->tabService,
        );
        $this->tui = new Tui(terminal: $this->terminal);
        $this->screen->mount($this->tui);
    }

    /**
     * Create a TabService with a single parent tab.
     */
    public static function createParentTabService(string $sessionId): TabService
    {
        $tabService = new TabService();
        $tabService->addTab(new TabDefinition(
            id: 'parent',
            label: 'Parent',
            runId: $sessionId,
            state: new TuiSessionState($sessionId, false),
            isRun: true,
        ));

        return $tabService;
    }

    public function tabService(): TabService
    {
        return $this->tabService;
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

    /**
     * Start terminal input handling (matches {@see Tui::start()} input wiring).
     *
     * Does not block on {@see Tui::run()}; drive input with {@see sendInput()}.
     */
    public function startInputLoop(): void
    {
        if ($this->inputLoopStarted) {
            return;
        }

        $this->tui->start();
        $this->tui->setFocus($this->screen->editorWidget());
        $this->inputLoopStarted = true;
    }

    /**
     * Simulate keyboard input through VirtualTerminal → Tui::handleInput().
     */
    public function sendInput(string $keys): void
    {
        if (!$this->inputLoopStarted) {
            throw new \LogicException('sendInput() requires startInputLoop() first.');
        }

        $this->terminal->simulateInput($keys);
        $this->tui->processRender();
    }

    public function stopInputLoop(): void
    {
        if (!$this->inputLoopStarted) {
            return;
        }

        $this->tui->stop();
        $this->inputLoopStarted = false;
    }

    public function render(): void
    {
        $this->tui->requestRender(force: true);
        $this->tui->processRender();
    }


    /**
     * Palette with distinct ANSI thinking tokens for virtual border-colour assertions.
     */
    public static function defaultVirtualPalette(): ThemePalette
    {
        return new ThemePalette('virtual-test', [
            ThemeColorEnum::ThinkingOff->value => 'white',
            ThemeColorEnum::ThinkingMinimal->value => 'cyan',
            ThemeColorEnum::ThinkingLow->value => 'yellow',
            ThemeColorEnum::ThinkingMedium->value => 'green',
            ThemeColorEnum::ThinkingHigh->value => 'magenta',
            ThemeColorEnum::ThinkingXhigh->value => 'red',
            ThemeColorEnum::ThinkingText->value => 'bright_white',
        ]);
    }

    public function ansiOutput(): string
    {
        $this->render();

        return $this->terminal->getOutput();
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
