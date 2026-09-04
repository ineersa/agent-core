<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Completion\SlashCommandCompletionProvider;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\CompletionListener;
use Ineersa\Tui\Listener\CompletionMenu;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Tui;

#[CoversClass(CompletionListener::class)]
#[CoversClass(CompletionMenu::class)]
final class CompletionListenerTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;
    private PromptEditor $editor;
    private TuiSessionState $state;
    private ChatScreen $screen;
    private Tui $tui;
    private SlashCommandCatalog $catalog;

    protected function setUp(): void
    {
        $this->editor = new PromptEditor();
        $this->state = new TuiSessionState('test-session');

        $theme = new DefaultTheme(new ThemePalette('default'));
        $this->screen = new ChatScreen($theme, 'test-session', $this->editor);
        $this->tui = new Tui();
        $this->screen->mount($this->tui);

        $this->catalog = new SlashCommandCatalog();

        $this->registerListener();
    }

    // ── Tab opens completion ──────────────────────────────────────

    #[Test]
    public function tabOpensSlashCompletionWhenSlashContextDetected(): void
    {
        $this->editor->typeText('/he');

        // Tab dispatches InputEvent; listener opens completion and stops propagation
        $this->tui->handleInput("\t");

        // Editor text must be unchanged (menu open, not yet accepted)
        $this->assertSame('/he', $this->editor->getText());
    }

    #[Test]
    public function tabDoesNothingWhenNoSlashContext(): void
    {
        $this->editor->typeText('hello');

        $this->tui->handleInput("\t");

        // Editor text unchanged — no completion triggered
        $this->assertSame('hello', $this->editor->getText());
    }

    // ── Tab accepts selected suggestion ───────────────────────────

    #[Test]
    public function tabAcceptsFirstSuggestionWhenMenuOpen(): void
    {
        $this->editor->typeText('/he');

        // First Tab: open menu
        $this->tui->handleInput("\t");
        $this->assertSame('/he', $this->editor->getText());

        // Second Tab: accept selected (first = /help)
        $this->tui->handleInput("\t");
        $this->assertSame('/help ', $this->editor->getText());
    }

    #[Test]
    public function tabAcceptsCorrectSuggestionAfterNavigation(): void
    {
        $this->editor->typeText('/');

        // Open menu
        $this->tui->handleInput("\t");

        // Navigate down twice, then accept
        $this->tui->handleInput("\x1b[B"); // Down
        $this->tui->handleInput("\x1b[B"); // Down again
        $this->tui->handleInput("\t");     // Accept

        // Built-in commands sorted alphabetically: /clear, /exit, /help
        // Index 0: /clear, index 1: /exit, index 2: /help
        // Down twice = index 2 = /help
        $this->assertSame('/help ', $this->editor->getText());
    }

    #[Test]
    public function tabAcceptsAfterUpNavigation(): void
    {
        $this->editor->typeText('/');

        // Open menu (index 0: /clear)
        $this->tui->handleInput("\t");

        // Press Up to wrap to last item (/hotkeys)
        $this->tui->handleInput("\x1b[A");

        // Accept
        $this->tui->handleInput("\t");

        $this->assertSame('/hotkeys ', $this->editor->getText());
    }

    // ── Multiline: slash after newline does not trigger ────────────

    #[Test]
    public function slashAfterNewlineDoesNotTriggerTabCompletion(): void
    {
        // Per MVP: slash completion only triggers when the full
        // text starts with "/", not when "/" appears after a newline.
        $this->editor->typeText("previous line\n/");

        // Tab does not open the completion menu.
        $this->tui->handleInput("\t");

        // Editor should contain the literal tab inserted by the editor,
        // not a completed suggestion.
        $this->assertStringNotContainsString('/exit', $this->editor->getText());
        $this->assertStringNotContainsString('/clear', $this->editor->getText());
        $this->assertStringNotContainsString('/help', $this->editor->getText());
    }

    #[Test]
    public function slashAfterNewlineWithPrefixDoesNotTriggerTabCompletion(): void
    {
        // Text starting with "/" then a newline then "/ex" — the
        // full text starts with "/" but the prefix after the first
        // "/" is "help\n/ex", which matches no command.
        $this->editor->typeText("/help\n/ex");

        $this->tui->handleInput("\t");

        // Editor should contain literal tab, not "/exit ".
        $this->assertStringNotContainsString('/exit', $this->editor->getText());
    }

    // ── Escape closes completion ──────────────────────────────────

    #[Test]
    public function escapeClosesCompletionWithoutClearingEditor(): void
    {
        $this->editor->typeText('/he');

        // Open menu
        $this->tui->handleInput("\t");

        // Close with Escape
        $this->tui->handleInput("\x1b");

        // Editor text unchanged — menu was closed without clearing
        $this->assertSame('/he', $this->editor->getText());
    }

    #[Test]
    public function escapeWithNoCompletionOpenPassesThrough(): void
    {
        $this->editor->typeText('/he');

        // No Tab first — Escape has nothing to close
        $this->tui->handleInput("\x1b");

        // Editor text unchanged (Escape passed through to editor)
        $this->assertSame('/he', $this->editor->getText());
    }

    // ── Up/Down navigation ────────────────────────────────────────

    #[Test]
    public function upAndDownNavigateSuggestionsWhenMenuOpen(): void
    {
        $this->editor->typeText('/');

        // Open menu
        $this->tui->handleInput("\t");

        // Down
        $this->tui->handleInput("\x1b[B");

        // Accept
        $this->tui->handleInput("\t");
        // Built-ins alphabetical: /clear (0), /exit (1), /help (2)
        // Down once = index 1 = /exit
        $this->assertSame('/exit ', $this->editor->getText());
    }

    #[Test]
    public function alternateUpSequenceWorks(): void
    {
        $this->editor->typeText('/');

        // Open menu
        $this->tui->handleInput("\t");

        // Up with alternate sequence \x1bOA
        $this->tui->handleInput("\x1bOA");

        // Accept — wraps to last (/hotkeys)
        $this->tui->handleInput("\t");

        $this->assertSame('/hotkeys ', $this->editor->getText());
    }

    #[Test]
    public function alternateDownSequenceWorks(): void
    {
        $this->editor->typeText('/');

        // Open menu
        $this->tui->handleInput("\t");

        // Down with alternate sequence \x1bOB
        $this->tui->handleInput("\x1bOB");

        // Accept — index 1 = /exit
        $this->tui->handleInput("\t");

        $this->assertSame('/exit ', $this->editor->getText());
    }

    #[Test]
    public function downNavigationScrollsNativeWindowBeyondMaxVisible(): void
    {
        // Built-ins: /clear /exit /help /hotkeys (4). Add enough extras so total > 10
        // (SelectListWidget maxVisible=10) and selection must scroll the window.
        for ($i = 0; $i < 12; ++$i) {
            $name = \sprintf('cmd%02d', $i);
            $this->catalog->register(
                new CommandMetadata(name: $name, aliases: [], description: 'extra '.$name),
                new readonly class implements SlashCommandHandler {
                    public function handle(\Ineersa\Tui\Command\SlashCommand $command): \Ineersa\Tui\Command\CommandResult
                    {
                        return new \Ineersa\Tui\Command\NoOp();
                    }
                },
            );
        }

        // Re-register listener against the expanded registry.
        $this->tui = new Tui();
        $this->editor = new PromptEditor();
        $theme = new DefaultTheme(new ThemePalette('default'));
        $this->screen = new ChatScreen($theme, 'test-session', $this->editor);
        $this->screen->mount($this->tui);
        $this->registerListener();

        $this->editor->typeText('/');
        $this->tui->handleInput("\t");

        // Move past the first page of 10 visible items (index 10 = 11th item).
        for ($i = 0; $i < 10; ++$i) {
            $this->tui->handleInput("\x1b[B");
        }

        $this->tui->handleInput("\t");
        $accepted = $this->editor->getText();
        $this->assertNotSame('/clear ', $accepted, 'Selection must leave the first item after 10 downs');
        $this->assertMatchesRegularExpression('#^/[a-z0-9]+ $#', $accepted);
        // With alphabetical slash suggestions, index 10 should not be /clear.
        $this->assertStringStartsWith('/', $accepted);
    }

    // ── Up/Down passes through when menu closed ──────────────────

    #[Test]
    public function upPassesThroughWhenCompletionClosed(): void
    {
        $this->editor->typeText('/he');

        // No Tab — menu closed
        // Up should NOT be consumed by completion; it passes through to editor
        $this->tui->handleInput("\x1b[A");

        // Editor text unchanged (Up handled by editor cursor movement or history)
        $this->assertSame('/he', $this->editor->getText());
    }

    #[Test]
    public function downPassesThroughWhenCompletionClosed(): void
    {
        $this->editor->typeText('/he');

        $this->tui->handleInput("\x1b[B");

        // Editor text unchanged
        $this->assertSame('/he', $this->editor->getText());
    }

    // ── Shift+Tab not stolen ──────────────────────────────────────

    #[Test]
    public function shiftTabIsNotConsumedByCompletion(): void
    {
        $this->editor->typeText('/');

        // Open menu
        $this->tui->handleInput("\t");

        // Shift+Tab should pass through (not consumed by completion)
        // Close menu first to avoid complexity
        $this->tui->handleInput("\x1b"); // closes menu
        $this->editor->typeText('/');     // reset

        // Shift+Tab without menu
        $this->tui->handleInput("\x1b[Z");

        // Editor text unchanged
        $this->assertSame('/', $this->editor->getText());
    }

    // ── Live completion opens on slash typing ─────────────────────

    #[Test]
    public function typingSlashOpensCompletionOverlay(): void
    {
        $this->tui->setFocus($this->screen->editorWidget());

        // Type '/' — completion should open based on predicted text.
        // Editor must still receive the '/' character.
        $this->tui->handleInput('/');

        $this->assertSame('/', $this->editor->getText());
    }

    #[Test]
    public function typingSlashThenLetterRefinesCompletion(): void
    {
        $this->tui->setFocus($this->screen->editorWidget());

        // Type '/h' — prediction '/h' should refine overlay to /help only.
        $this->tui->handleInput('/');
        $this->tui->handleInput('h');

        // Editor has '/h' inserted naturally.
        $this->assertSame('/h', $this->editor->getText());

        // Tab should accept the first (and only) suggestion /help.
        $this->tui->handleInput("\t");
        $this->assertSame('/help ', $this->editor->getText());
    }

    #[Test]
    public function typingSlashThenRefineThenAcceptWorks(): void
    {
        $this->tui->setFocus($this->screen->editorWidget());

        // Type '/e' — should refine to /exit.
        $this->tui->handleInput('/');
        $this->tui->handleInput('e');

        $this->assertSame('/e', $this->editor->getText());

        // Tab accepts the matching suggestion.
        $this->tui->handleInput("\t");
        $this->assertSame('/exit ', $this->editor->getText());
    }

    #[Test]
    public function typingNonSlashTextDoesNotOpenCompletion(): void
    {
        $this->tui->setFocus($this->screen->editorWidget());

        // Type "hello" — no slash context, completion must NOT open.
        $this->tui->handleInput('h');
        $this->tui->handleInput('e');

        $this->assertSame('he', $this->editor->getText());

        // Tab on non-slash text must not trigger completion.
        $this->tui->handleInput("\t");
        $this->assertSame('he', $this->editor->getText());
    }

    #[Test]
    public function backspaceRefinesCompletionToWiderMatch(): void
    {
        $this->tui->setFocus($this->screen->editorWidget());

        // Type '/h' — completion opens with /help.
        $this->tui->handleInput('/');
        $this->tui->handleInput('h');

        $this->assertSame('/h', $this->editor->getText());

        // Backspace removes 'h' — predicted text is '/' which still matches.
        $this->tui->handleInput("\x7f");

        $this->assertSame('/', $this->editor->getText());
    }

    #[Test]
    public function backspaceToEmptyClosesCompletionOverlay(): void
    {
        $this->tui->setFocus($this->screen->editorWidget());

        // Type '/' — live completion opens.
        $this->tui->handleInput('/');
        $this->assertSame('/', $this->editor->getText());

        // Backspace to empty — predicted text '' has no slash context,
        // overlay must close.
        $this->tui->handleInput("\x7f");
        $this->assertSame('', $this->editor->getText());

        // Verify completion is closed: Tab on empty does nothing.
        $this->tui->handleInput("\t");
        $this->assertSame('', $this->editor->getText());
    }

    #[Test]
    public function liveTypingRefinesButTabStillRequiredToAccept(): void
    {
        $this->tui->setFocus($this->screen->editorWidget());

        // Type '/help' — typing should refine but NOT auto-accept.
        $this->tui->handleInput('/');
        $this->tui->handleInput('h');
        $this->tui->handleInput('e');
        $this->tui->handleInput('l');
        $this->tui->handleInput('p');

        // Editor has '/help' exactly as typed — not '/help '.
        $this->assertSame('/help', $this->editor->getText());

        // Tab still accepts and inserts trailing space.
        $this->tui->handleInput("\t");
        $this->assertSame('/help ', $this->editor->getText());
    }

    #[Test]
    public function upDownNavigationWorksAfterLiveCompletionOpen(): void
    {
        $this->tui->setFocus($this->screen->editorWidget());

        // Type '/' — live completion opens (no Tab needed).
        $this->tui->handleInput('/');

        // Navigate Down — moves to index 1 (/exit, alphabetically
        // after /clear at index 0).
        $this->tui->handleInput("\x1b[B");

        // Tab accepts the navigated (not first) suggestion.
        $this->tui->handleInput("\t");
        $this->assertSame('/exit ', $this->editor->getText());
    }

    #[Test]
    public function typingNoMatchInputWhileMenuOpenClosesOverlay(): void
    {
        // Simulate natural typing: focus editor and type '/'
        $this->tui->setFocus($this->screen->editorWidget());
        $this->tui->handleInput('/');

        // Overlay is already open from live completion.  No Tab needed.
        // Type 'x' — predicted '/x' has no suggestions, overlay closes.
        // Editor must still receive the 'x'.
        $this->tui->handleInput('x');

        // Text should be "/x" (cursor was after '/' from natural typing)
        $this->assertSame('/x', $this->editor->getText());
    }

    #[Test]
    public function afterNoMatchInputClosesMenuTabCanReopen(): void
    {
        // Simulate natural typing: focus editor and type '/'
        $this->tui->setFocus($this->screen->editorWidget());
        $this->tui->handleInput('/');

        // Type 'x' — closes menu, inserts 'x'
        $this->tui->handleInput('x');

        // Text is now "/x" — no longer matching a known command prefix
        $this->tui->handleInput("\t");

        // Editor text unchanged after Tab (no suggestions for "/x")
        $this->assertSame('/x', $this->editor->getText());
    }

    // ── Alias acceptance inserts canonical ────────────────────────

    #[Test]
    public function aliasPrefixAcceptedInsertsCanonicalCommand(): void
    {
        // "/q" matches /exit alias "q"
        $this->editor->typeText('/q');

        // Open menu
        $this->tui->handleInput("\t");

        // Accept first suggestion (should be /exit)
        $this->tui->handleInput("\t");

        $this->assertSame('/exit ', $this->editor->getText());
    }

    // ── Enter accepts + submits ───────────────────────────────────

    #[Test]
    public function enterAcceptsSuggestionAndSubmitsWhenMenuOpen(): void
    {
        $this->tui->setFocus($this->screen->editorWidget());

        // Wire onSubmit to capture the submitted text from EditorWidget.
        $submittedText = null;
        $this->screen->editorWidget()->onSubmit(
            static function (\Symfony\Component\Tui\Event\SubmitEvent $event) use (&$submittedText): void {
                $submittedText = $event->getValue();
            },
        );

        $this->editor->typeText('/he');

        // Tab: open menu
        $this->tui->handleInput("\t");
        $this->assertSame('/he', $this->editor->getText());

        // Enter: accept first suggestion (/help) + let Enter propagate to
        // EditorWidget → submit fires.
        $this->tui->handleInput("\n");

        // Editor text was set to '/help ' by completion acceptance.
        // SubmitListener would normally extract() and clear, but in this
        // fixture only EditorWidget's raw SubmitEvent fires.
        $this->assertSame('/help ', $this->editor->getText());

        // onSubmit callback received the completed command text.
        $this->assertSame('/help ', $submittedText);
    }

    #[Test]
    public function enterAfterNavigationSubmitsSelectedCommand(): void
    {
        $this->tui->setFocus($this->screen->editorWidget());

        $submittedText = null;
        $this->screen->editorWidget()->onSubmit(
            static function (\Symfony\Component\Tui\Event\SubmitEvent $event) use (&$submittedText): void {
                $submittedText = $event->getValue();
            },
        );

        $this->editor->typeText('/');

        // Tab: open menu
        $this->tui->handleInput("\t");

        // Navigate Down twice (built-ins: /clear, /exit, /help)
        $this->tui->handleInput("\x1b[B");
        $this->tui->handleInput("\x1b[B");

        // Enter: accept /help + submit
        $this->tui->handleInput("\n");

        $this->assertSame('/help ', $this->editor->getText());
        $this->assertSame('/help ', $submittedText);
    }

    #[Test]
    public function enterPassesThroughWhenMenuClosed(): void
    {
        $this->tui->setFocus($this->screen->editorWidget());

        // No slash context — completion stays closed for Enter.
        $this->editor->typeText('hello');

        $submittedText = null;
        $this->screen->editorWidget()->onSubmit(
            static function (\Symfony\Component\Tui\Event\SubmitEvent $event) use (&$submittedText): void {
                $submittedText = $event->getValue();
            },
        );

        $this->tui->handleInput("\n");

        // Completion has no menu open — Enter passes through.
        $this->assertSame('hello', $this->editor->getText());
        $this->assertSame('hello', $submittedText);
    }

    // ── Cursor at end after acceptance ────────────────────────────

    #[Test]
    public function typingAfterTabAcceptAppendsArgsAfterCommand(): void
    {
        $this->tui->setFocus($this->screen->editorWidget());

        $this->editor->typeText('/');

        // Navigate to /help (index 2 of built-ins: clear, exit, help)
        $this->tui->handleInput("\t");   // open
        $this->tui->handleInput("\x1b[B"); // down → /exit
        $this->tui->handleInput("\x1b[B"); // down → /help
        $this->tui->handleInput("\t");   // accept

        $this->assertSame('/help ', $this->editor->getText());

        // Type additional arguments — must appear AFTER the command.
        $this->tui->handleInput('f');
        $this->tui->handleInput('o');
        $this->tui->handleInput('o');

        $this->assertSame('/help foo', $this->editor->getText());
    }

    #[Test]
    public function enterAcceptThenTypeArgsWorks(): void
    {
        $this->tui->setFocus($this->screen->editorWidget());

        $this->editor->typeText('/ex');

        // Tab: open (only /exit matches /ex)
        $this->tui->handleInput("\t");

        // Enter: accept /exit + submit propagates
        $this->tui->handleInput("\n");

        $this->assertSame('/exit ', $this->editor->getText());

        // Type args after Enter-submit acceptance
        $this->tui->handleInput('a');
        $this->tui->handleInput('r');
        $this->tui->handleInput('g');

        $this->assertSame('/exit arg', $this->editor->getText());
    }

    // ── Command execution not invoked on Tab ──────────────────────

    #[Test]
    public function tabDoesNotExecuteSlashCommand(): void
    {
        // Register a test command and verify it's NEVER invoked via Tab
        $callCount = new \stdClass();
        $callCount->called = false;
        $handler = new readonly class($callCount) implements SlashCommandHandler {
            public function __construct(
                private \stdClass $callCount,
            ) {
            }

            public function handle(\Ineersa\Tui\Command\SlashCommand $command): \Ineersa\Tui\Command\CommandResult
            {
                $this->callCount->called = true;

                return new \Ineersa\Tui\Command\NoOp();
            }
        };

        // Isolated TUI + screen — does NOT reuse setUp's listener.
        $isolatedTui = new Tui();
        $isolatedEditor = new PromptEditor();
        $theme = new DefaultTheme(new ThemePalette('default'));
        $isolatedScreen = new ChatScreen($theme, 'test-session', $isolatedEditor);
        $isolatedScreen->mount($isolatedTui);
        $isolatedTui->setFocus($isolatedScreen->editorWidget());

        $catalog = new SlashCommandCatalog();
        $catalog->register(
            new CommandMetadata(name: 'testcmd', aliases: [], description: 'Test'),
            $handler,
        );

        $provider = new SlashCommandCompletionProvider($catalog);
        $listener = new CompletionListener($provider);

        $client = $this->createStub(AgentSessionClient::class);
        $state = new TuiSessionState('test-session');
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: sys_get_temp_dir(),
        );
        $sessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
            dispatcher: new \Symfony\Component\EventDispatcher\EventDispatcher(),
        );
        $isolatedContext = new TuiRuntimeContext(
            tui: $isolatedTui,
            client: $client,
            state: $state,
            screen: $isolatedScreen,
            sessionStore: $sessionStore,
            ticks: new \Ineersa\Tui\Runtime\TuiTickDispatcher(),
            switch: $this->createStub(\Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface::class),
            lifecycle: new \Ineersa\Tui\Runtime\TuiSessionLifecycleDispatcher(),
            historyProvider: $this->createStub(\Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface::class),
            sessionServices: $this->createSessionServices(),
        );
        $listener->register($isolatedContext);

        $isolatedEditor->typeText('/test');

        // Tab opens completion, Tab accepts
        $isolatedTui->handleInput("\t");
        $isolatedTui->handleInput("\t");

        $this->assertFalse($callCount->called, 'Slash command handler must not be invoked via Tab completion.');
        $this->assertSame('/testcmd ', $isolatedEditor->getText());
    }

    // ── Ctrl+C / Ctrl+D tears down completion overlay ────────────

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideCtrlCSequences(): iterable
    {
        yield 'legacy' => ["\x03"];
        yield 'kitty' => ["\x1b[99;5u"];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideCtrlDSequences(): iterable
    {
        yield 'legacy' => ["\x04"];
        yield 'kitty' => ["\x1b[100;5u"];
    }

    #[Test]
    #[DataProvider('provideCtrlCSequences')]
    public function ctrlCClearsCompletionOverlay(string $sequence): void
    {
        $this->assertCompletionOverlayClosesOnInterrupt($sequence);
    }

    #[Test]
    #[DataProvider('provideCtrlDSequences')]
    public function ctrlDClearsCompletionOverlay(string $sequence): void
    {
        $this->assertCompletionOverlayClosesOnInterrupt($sequence);
    }

    #[Test]
    #[DataProvider('provideCtrlCSequences')]
    public function ctrlCDoesNothingWhenCompletionClosed(string $sequence): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'completion-ctrl-closed');
        $provider = new SlashCommandCompletionProvider(new SlashCommandCatalog());
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState(new TuiSessionState('completion-ctrl-closed'))
            ->withScreen($harness->screen())
            ->build();
        (new CompletionListener($provider))->register($context);

        try {
            $harness->startInputLoop();
            $harness->screen()->promptEditor()->typeText('hello');
            $harness->render();

            $this->assertStringNotContainsString(
                'Completions — arrows move',
                $harness->plainScreenText(),
                'Completion overlay must stay closed before interrupt',
            );

            $harness->sendInput($sequence);

            $this->assertStringNotContainsString(
                'Completions — arrows move',
                $harness->plainScreenText(),
                'Ctrl+C with no open completion must not open the overlay',
            );
            $this->assertSame('hello', $harness->screen()->promptEditor()->getText());
        } finally {
            $harness->stopInputLoop();
        }
    }

    // ── Overlay lifecycle (open/close is idempotent) ──────────────

    #[Test]
    public function repeatedOpenCloseDoesNotThrowOrCorruptState(): void
    {
        $this->editor->typeText('/');

        // Open
        $this->tui->handleInput("\t");

        // Close
        $this->tui->handleInput("\x1b");

        // Open again (on different text)
        $this->editor->typeText('/he');
        $this->tui->handleInput("\t");

        // Accept: should still work and insert /help
        $this->tui->handleInput("\t");

        $this->assertSame('/help ', $this->editor->getText());

        // Close should still work after acceptance
        // (no-op since already closed, but shouldn't throw)
        $this->editor->typeText('/');
        $this->tui->handleInput("\t");
        $this->tui->handleInput("\x1b");

        $this->assertSame('/', $this->editor->getText());
    }

    // ── @ file mention completion ──────────────────────────────────

    #[Test]
    public function tabOpensFileCompletionForAtContext(): void
    {
        // Create a provider with a fake in-memory index.
        $tmpDir = sys_get_temp_dir().'/editor09-listener-'.getmypid().'-'.hrtime(true);
        mkdir($tmpDir, 0755, true);
        $indexPath = $tmpDir.'/index.jsonl';

        try {
            file_put_contents($indexPath, implode("\n", [
                '{"path":"src/foo.php","dir":false}',
                '{"path":"src/bar.php","dir":false}',
            ]));

            $reader = new \Ineersa\Tui\Completion\FileMentionIndexReader($indexPath);
            $fileProvider = new \Ineersa\Tui\Completion\FileMentionCompletionProvider($reader);
            $registry = new \Ineersa\Tui\Completion\CompletionProviderRegistry([
                new SlashCommandCompletionProvider($this->catalog),
                $fileProvider,
            ]);

            // Create fresh TUI and editor to avoid interference from setUp.
            $isolatedTui = new Tui();
            $isolatedEditor = new PromptEditor();
            $theme = new DefaultTheme(new ThemePalette('default'));
            $isolatedScreen = new ChatScreen($theme, 'test-session', $isolatedEditor);
            $isolatedScreen->mount($isolatedTui);
            $isolatedTui->setFocus($isolatedScreen->editorWidget());

            $listener = new CompletionListener($registry);

            $appConfig = new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: sys_get_temp_dir(),
            );
            $sessionStore = new HatfieldSessionStore(
                appConfig: $appConfig,
                entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
                dispatcher: new \Symfony\Component\EventDispatcher\EventDispatcher(), );
            $context = new TuiRuntimeContext(
                tui: $isolatedTui,
                client: $this->createStub(AgentSessionClient::class),
                state: new TuiSessionState('test-session'),
                screen: $isolatedScreen,
                sessionStore: $sessionStore,
                ticks: new \Ineersa\Tui\Runtime\TuiTickDispatcher(),
                switch: $this->createStub(\Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface::class),
                lifecycle: new \Ineersa\Tui\Runtime\TuiSessionLifecycleDispatcher(),
                historyProvider: $this->createStub(\Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface::class),
                sessionServices: $this->createSessionServices(),
            );
            $listener->register($context);

            // Tab on @ should open completion.
            $isolatedEditor->typeText('@');
            $isolatedTui->handleInput("\t");

            // Tab again should accept the first suggestion.
            $isolatedTui->handleInput("\t");

            $this->assertStringStartsWith('@src/', $isolatedEditor->getText());
        } finally {
            @unlink($indexPath);
            @rmdir($tmpDir);
        }
    }

    #[Test]
    public function liveFileNameCompletionOpensOnAt(): void
    {
        $tmpDir = sys_get_temp_dir().'/editor09-live-'.getmypid().'-'.hrtime(true);
        mkdir($tmpDir, 0755, true);
        $indexPath = $tmpDir.'/index.jsonl';

        try {
            file_put_contents($indexPath, '{"path":"my-file.php","dir":false}');

            $reader = new \Ineersa\Tui\Completion\FileMentionIndexReader($indexPath);
            $fileProvider = new \Ineersa\Tui\Completion\FileMentionCompletionProvider($reader);
            $registry = new \Ineersa\Tui\Completion\CompletionProviderRegistry([
                new SlashCommandCompletionProvider($this->catalog),
                $fileProvider,
            ]);

            $isolatedTui = new Tui();
            $isolatedEditor = new PromptEditor();
            $theme = new DefaultTheme(new ThemePalette('default'));
            $isolatedScreen = new ChatScreen($theme, 'test-session', $isolatedEditor);
            $isolatedScreen->mount($isolatedTui);
            $isolatedTui->setFocus($isolatedScreen->editorWidget());

            $listener = new CompletionListener($registry);

            $appConfig = new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: sys_get_temp_dir(),
            );
            $sessionStore = new HatfieldSessionStore(
                appConfig: $appConfig,
                entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
                dispatcher: new \Symfony\Component\EventDispatcher\EventDispatcher(), );
            $context = new TuiRuntimeContext(
                tui: $isolatedTui,
                client: $this->createStub(AgentSessionClient::class),
                state: new TuiSessionState('test-session'),
                screen: $isolatedScreen,
                sessionStore: $sessionStore,
                ticks: new \Ineersa\Tui\Runtime\TuiTickDispatcher(),
                switch: $this->createStub(\Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface::class),
                lifecycle: new \Ineersa\Tui\Runtime\TuiSessionLifecycleDispatcher(),
                historyProvider: $this->createStub(\Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface::class),
                sessionServices: $this->createSessionServices(),
            );
            $listener->register($context);

            // Typing @ should open completion live.
            $isolatedEditor->typeText('');
            // handleInput("@") both inserts @ and triggers live completion.
            $isolatedTui->handleInput('@');

            // Tab should accept.
            $isolatedTui->handleInput("\t");
            $this->assertStringStartsWith('@my-file.php', $isolatedEditor->getText());
        } finally {
            @unlink($indexPath);
            @rmdir($tmpDir);
        }
    }

    #[Test]
    public function escapeClosesFileCompletionWithoutClearingEditor(): void
    {
        $tmpDir = sys_get_temp_dir().'/editor09-escape-'.getmypid().'-'.hrtime(true);
        mkdir($tmpDir, 0755, true);
        $indexPath = $tmpDir.'/index.jsonl';

        try {
            file_put_contents($indexPath, '{"path":"src/file.php","dir":false}');

            $reader = new \Ineersa\Tui\Completion\FileMentionIndexReader($indexPath);
            $fileProvider = new \Ineersa\Tui\Completion\FileMentionCompletionProvider($reader);

            $isolatedTui = new Tui();
            $isolatedEditor = new PromptEditor();
            $theme = new DefaultTheme(new ThemePalette('default'));
            $isolatedScreen = new ChatScreen($theme, 'test-session', $isolatedEditor);
            $isolatedScreen->mount($isolatedTui);
            $isolatedTui->setFocus($isolatedScreen->editorWidget());

            $listener = new CompletionListener($fileProvider);

            $appConfig = new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: sys_get_temp_dir(),
            );
            $sessionStore = new HatfieldSessionStore(
                appConfig: $appConfig,
                entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
                dispatcher: new \Symfony\Component\EventDispatcher\EventDispatcher(), );
            $context = new TuiRuntimeContext(
                tui: $isolatedTui,
                client: $this->createStub(AgentSessionClient::class),
                state: new TuiSessionState('test-session'),
                screen: $isolatedScreen,
                sessionStore: $sessionStore,
                ticks: new \Ineersa\Tui\Runtime\TuiTickDispatcher(),
                switch: $this->createStub(\Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface::class),
                lifecycle: new \Ineersa\Tui\Runtime\TuiSessionLifecycleDispatcher(),
                historyProvider: $this->createStub(\Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface::class),
                sessionServices: $this->createSessionServices(),
            );
            $listener->register($context);

            $isolatedEditor->typeText('@');
            // Open via Tab.
            $isolatedTui->handleInput("\t");

            // Escape closes without clearing.
            $isolatedTui->handleInput("\x1b");
            $this->assertSame('@', $isolatedEditor->getText());
        } finally {
            @unlink($indexPath);
            @rmdir($tmpDir);
        }
    }

    #[Test]
    public function tabAcceptsFileCompletionInMultilineEditor(): void
    {
        // Reproduces GitHub issue #123: typing "Hello\n\n@" then
        // Tab/Tab accepts a file completion without clearing the
        // preceding multiline content.
        $tmpDir = sys_get_temp_dir().'/editor09-multiline-'.getmypid().'-'.hrtime(true);
        mkdir($tmpDir, 0755, true);
        $indexPath = $tmpDir.'/index.jsonl';

        try {
            file_put_contents($indexPath, implode("\n", [
                '{"path":"src/file.php","dir":false}',
                '{"path":"src/other.php","dir":false}',
            ]));

            $reader = new \Ineersa\Tui\Completion\FileMentionIndexReader($indexPath);
            $fileProvider = new \Ineersa\Tui\Completion\FileMentionCompletionProvider($reader);

            $isolatedTui = new Tui();
            $isolatedEditor = new PromptEditor();
            $theme = new DefaultTheme(new ThemePalette('default'));
            $isolatedScreen = new ChatScreen($theme, 'test-session', $isolatedEditor);
            $isolatedScreen->mount($isolatedTui);
            $isolatedTui->setFocus($isolatedScreen->editorWidget());

            $listener = new CompletionListener($fileProvider);

            $appConfig = new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: sys_get_temp_dir(),
            );
            $sessionStore = new HatfieldSessionStore(
                appConfig: $appConfig,
                entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
                dispatcher: new \Symfony\Component\EventDispatcher\EventDispatcher(), );
            $context = new TuiRuntimeContext(
                tui: $isolatedTui,
                client: $this->createStub(AgentSessionClient::class),
                state: new TuiSessionState('test-session'),
                screen: $isolatedScreen,
                sessionStore: $sessionStore,
                ticks: new \Ineersa\Tui\Runtime\TuiTickDispatcher(),
                switch: $this->createStub(\Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface::class),
                lifecycle: new \Ineersa\Tui\Runtime\TuiSessionLifecycleDispatcher(),
                historyProvider: $this->createStub(\Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface::class),
                sessionServices: $this->createSessionServices(),
            );
            $listener->register($context);

            // Set multiline editor text: "Hello", blank line, "@"
            $isolatedEditor->typeText("Hello\n\n@");

            // First Tab: open completion menu
            $isolatedTui->handleInput("\t");
            // Second Tab: accept first suggestion
            $isolatedTui->handleInput("\t");

            // Editor must retain the preceding multiline content.
            $this->assertStringContainsString('Hello', $isolatedEditor->getText());
            $this->assertStringContainsString('@src/', $isolatedEditor->getText());
            // The editor must NOT be empty or collapsed to a single line.
            $this->assertStringNotContainsString('Hello@', $isolatedEditor->getText());
        } finally {
            @unlink($indexPath);
            @rmdir($tmpDir);
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────

    private function registerListener(): void
    {
        $provider = new SlashCommandCompletionProvider($this->catalog);
        $listener = new CompletionListener($provider);

        $context = $this->createContext();
        $listener->register($context);
    }

    private function createContext(): TuiRuntimeContext
    {
        return $this->buildTuiContext()
            ->withTui($this->tui)
            ->withState($this->state)
            ->withScreen($this->screen)
            ->build();
    }

    private function assertCompletionOverlayClosesOnInterrupt(string $sequence): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'completion-ctrl-interrupt');
        $provider = new SlashCommandCompletionProvider(new SlashCommandCatalog());
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState(new TuiSessionState('completion-ctrl-interrupt'))
            ->withScreen($harness->screen())
            ->build();
        (new CompletionListener($provider))->register($context);

        try {
            $harness->startInputLoop();
            $harness->screen()->promptEditor()->typeText('/');
            $harness->sendInput("\t");

            $openScreen = $harness->plainScreenText();
            $this->assertStringContainsString(
                'Completions — arrows move',
                $openScreen,
                'Tab should open the completion overlay before interrupt',
            );

            $harness->sendInput($sequence);

            $this->assertStringNotContainsString(
                'Completions — arrows move',
                $harness->plainScreenText(),
                'Interrupt sequence must close the completion overlay',
            );

            // State machine remains healthy: reopen + accept first suggestion.
            $harness->screen()->promptEditor()->typeText('/');
            $harness->sendInput("\t");
            $harness->sendInput("\t");
            $this->assertStringStartsWith('/clear', $harness->screen()->promptEditor()->getText());
        } finally {
            $harness->stopInputLoop();
        }
    }
}
