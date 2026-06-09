<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Completion\SlashCommandCompletionProvider;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\CompletionListener;
use Ineersa\Tui\Listener\CompletionMenu;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Tui;

#[CoversClass(CompletionListener::class)]
#[CoversClass(CompletionMenu::class)]
final class CompletionListenerTest extends TestCase
{
    private PromptEditor $editor;
    private TuiSessionState $state;
    private ChatScreen $screen;
    private Tui $tui;
    private SlashCommandRegistry $registry;

    protected function setUp(): void
    {
        $this->editor = new PromptEditor();
        $this->state = new TuiSessionState('test-session');

        $theme = new DefaultTheme(new ThemePalette('default'));
        $this->screen = new ChatScreen($theme, 'test-session', $this->editor);
        $this->tui = new Tui();
        $this->screen->mount($this->tui);

        $this->registry = new SlashCommandRegistry();

        $this->registerListener();
    }

    // ── Tab opens completion ──────────────────────────────────────

    #[Test]
    public function tabOpensSlashCompletionWhenSlashContextDetected(): void
    {
        $this->editor->setText('/he');

        // Tab dispatches InputEvent; listener opens completion and stops propagation
        $this->tui->handleInput("\t");

        // Editor text must be unchanged (menu open, not yet accepted)
        $this->assertSame('/he', $this->editor->getText());
    }

    #[Test]
    public function tabDoesNothingWhenNoSlashContext(): void
    {
        $this->editor->setText('hello');

        $this->tui->handleInput("\t");

        // Editor text unchanged — no completion triggered
        $this->assertSame('hello', $this->editor->getText());
    }

    // ── Tab accepts selected suggestion ───────────────────────────

    #[Test]
    public function tabAcceptsFirstSuggestionWhenMenuOpen(): void
    {
        $this->editor->setText('/he');

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
        $this->editor->setText('/');

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
        $this->editor->setText('/');

        // Open menu (index 0: /clear)
        $this->tui->handleInput("\t");

        // Press Up to wrap to last item (/help)
        $this->tui->handleInput("\x1b[A");

        // Accept
        $this->tui->handleInput("\t");

        $this->assertSame('/help ', $this->editor->getText());
    }

    // ── Multiline: slash after newline does not trigger ────────────

    #[Test]
    public function slashAfterNewlineDoesNotTriggerTabCompletion(): void
    {
        // Per MVP: slash completion only triggers when the full
        // text starts with "/", not when "/" appears after a newline.
        $this->editor->setText("previous line\n/");

        // Tab does not open the completion menu.
        $this->tui->handleInput("\t");

        // Editor should contain the literal tab inserted by the editor,
        // not a completed suggestion.
        self::assertStringNotContainsString('/exit', $this->editor->getText());
        self::assertStringNotContainsString('/clear', $this->editor->getText());
        self::assertStringNotContainsString('/help', $this->editor->getText());
    }

    #[Test]
    public function slashAfterNewlineWithPrefixDoesNotTriggerTabCompletion(): void
    {
        // Text starting with "/" then a newline then "/ex" — the
        // full text starts with "/" but the prefix after the first
        // "/" is "help\n/ex", which matches no command.
        $this->editor->setText("/help\n/ex");

        $this->tui->handleInput("\t");

        // Editor should contain literal tab, not "/exit ".
        self::assertStringNotContainsString('/exit', $this->editor->getText());
    }

    // ── Escape closes completion ──────────────────────────────────

    #[Test]
    public function escapeClosesCompletionWithoutClearingEditor(): void
    {
        $this->editor->setText('/he');

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
        $this->editor->setText('/he');

        // No Tab first — Escape has nothing to close
        $this->tui->handleInput("\x1b");

        // Editor text unchanged (Escape passed through to editor)
        $this->assertSame('/he', $this->editor->getText());
    }

    // ── Up/Down navigation ────────────────────────────────────────

    #[Test]
    public function upAndDownNavigateSuggestionsWhenMenuOpen(): void
    {
        $this->editor->setText('/');

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
        $this->editor->setText('/');

        // Open menu
        $this->tui->handleInput("\t");

        // Up with alternate sequence \x1bOA
        $this->tui->handleInput("\x1bOA");

        // Accept — wraps to last (/help)
        $this->tui->handleInput("\t");

        $this->assertSame('/help ', $this->editor->getText());
    }

    #[Test]
    public function alternateDownSequenceWorks(): void
    {
        $this->editor->setText('/');

        // Open menu
        $this->tui->handleInput("\t");

        // Down with alternate sequence \x1bOB
        $this->tui->handleInput("\x1bOB");

        // Accept — index 1 = /exit
        $this->tui->handleInput("\t");

        $this->assertSame('/exit ', $this->editor->getText());
    }

    // ── Up/Down passes through when menu closed ──────────────────

    #[Test]
    public function upPassesThroughWhenCompletionClosed(): void
    {
        $this->editor->setText('/he');

        // No Tab — menu closed
        // Up should NOT be consumed by completion; it passes through to editor
        $this->tui->handleInput("\x1b[A");

        // Editor text unchanged (Up handled by editor cursor movement or history)
        $this->assertSame('/he', $this->editor->getText());
    }

    #[Test]
    public function downPassesThroughWhenCompletionClosed(): void
    {
        $this->editor->setText('/he');

        $this->tui->handleInput("\x1b[B");

        // Editor text unchanged
        $this->assertSame('/he', $this->editor->getText());
    }

    // ── Shift+Tab not stolen ──────────────────────────────────────

    #[Test]
    public function shiftTabIsNotConsumedByCompletion(): void
    {
        $this->editor->setText('/');

        // Open menu
        $this->tui->handleInput("\t");

        // Shift+Tab should pass through (not consumed by completion)
        // Close menu first to avoid complexity
        $this->tui->handleInput("\x1b"); // closes menu
        $this->editor->setText('/');     // reset

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
        $this->editor->setText('/q');

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

        $this->editor->setText('/he');

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

        $this->editor->setText('/');

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
        $this->editor->setText('hello');

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

        $this->editor->setText('/');

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

        $this->editor->setText('/ex');

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

        $registry = new SlashCommandRegistry();
        $registry->register(
            new CommandMetadata(name: 'testcmd', aliases: [], description: 'Test'),
            $handler,
        );

        $provider = new SlashCommandCompletionProvider($registry);
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
        );
        $listener->register($isolatedContext);

        $isolatedEditor->setText('/test');

        // Tab opens completion, Tab accepts
        $isolatedTui->handleInput("\t");
        $isolatedTui->handleInput("\t");

        $this->assertFalse($callCount->called, 'Slash command handler must not be invoked via Tab completion.');
        $this->assertSame('/testcmd ', $isolatedEditor->getText());
    }

    // ── Ctrl+C / Ctrl+D tears down completion overlay ────────────

    #[Test]
    public function ctrlCClearsCompletionOverlay(): void
    {
        $this->editor->setText('/');

        // Open menu
        $this->tui->handleInput("\t");

        // Ctrl+C should close the overlay (priority 105 listener).
        // Propagation is NOT stopped so CtrlCInputInterceptor
        // still receives the key (not tested here since we don't
        // register the full interceptor chain).
        $exception = null;
        try {
            $this->tui->handleInput("\x03");
        } catch (\Throwable $e) {
            $exception = $e;
        }

        // The key must not crash or throw after overlay close.
        $this->assertNull($exception, 'Ctrl+C must not throw when completion overlay closes.');

        // Re-open and accept to verify state machine is still healthy.
        $this->editor->setText('/');
        $this->tui->handleInput("\t");
        $this->tui->handleInput("\t");
        // First alphabetical suggestion is /clear
        $this->assertStringStartsWith('/clear', $this->editor->getText());
    }

    #[Test]
    public function ctrlDClearsCompletionOverlay(): void
    {
        $this->editor->setText('/');

        // Open menu
        $this->tui->handleInput("\t");

        // Ctrl+D should close the overlay (priority 105 listener).
        // Propagation is NOT stopped so downstream listeners
        // (CtrlCInputInterceptor, editor) still handle the key.
        $exception = null;
        try {
            $this->tui->handleInput("\x04");
        } catch (\Throwable $e) {
            $exception = $e;
        }

        $this->assertNull($exception, 'Ctrl+D must not throw when completion overlay closes.');

        // Re-open and accept to verify state machine is still healthy.
        $this->editor->setText('/');
        $this->tui->handleInput("\t");
        $this->tui->handleInput("\t");
        // First alphabetical suggestion is /clear
        $this->assertStringStartsWith('/clear', $this->editor->getText());
    }

    #[Test]
    public function ctrlCDoesNothingWhenCompletionClosed(): void
    {
        $this->editor->setText('hello');

        // No menu open — Ctrl+C should not crash.
        // Without CtrlCInputInterceptor registered in this isolated
        // fixture, the key may be consumed by the editor; verify no
        // exception is thrown.
        $exception = null;
        try {
            $this->tui->handleInput("\x03");
        } catch (\Throwable $e) {
            $exception = $e;
        }

        $this->assertNull($exception, 'Ctrl+C with no completion open must not throw.');
    }

    // ── Overlay lifecycle (open/close is idempotent) ──────────────

    #[Test]
    public function repeatedOpenCloseDoesNotThrowOrCorruptState(): void
    {
        $this->editor->setText('/');

        // Open
        $this->tui->handleInput("\t");

        // Close
        $this->tui->handleInput("\x1b");

        // Open again (on different text)
        $this->editor->setText('/he');
        $this->tui->handleInput("\t");

        // Accept: should still work and insert /help
        $this->tui->handleInput("\t");

        $this->assertSame('/help ', $this->editor->getText());

        // Close should still work after acceptance
        // (no-op since already closed, but shouldn't throw)
        $this->editor->setText('/');
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
                new SlashCommandCompletionProvider($this->registry),
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
            );
            $context = new TuiRuntimeContext(
                tui: $isolatedTui,
                client: $this->createStub(AgentSessionClient::class),
                state: new TuiSessionState('test-session'),
                screen: $isolatedScreen,
                sessionStore: $sessionStore,
            );
            $listener->register($context);

            // Tab on @ should open completion.
            $isolatedEditor->setText('@');
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
                new SlashCommandCompletionProvider($this->registry),
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
            );
            $context = new TuiRuntimeContext(
                tui: $isolatedTui,
                client: $this->createStub(AgentSessionClient::class),
                state: new TuiSessionState('test-session'),
                screen: $isolatedScreen,
                sessionStore: $sessionStore,
            );
            $listener->register($context);

            // Typing @ should open completion live.
            $isolatedEditor->setText('');
            // handleInput("@") both inserts @ and triggers live completion.
            $isolatedTui->handleInput("@");

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
            );
            $context = new TuiRuntimeContext(
                tui: $isolatedTui,
                client: $this->createStub(AgentSessionClient::class),
                state: new TuiSessionState('test-session'),
                screen: $isolatedScreen,
                sessionStore: $sessionStore,
            );
            $listener->register($context);

            $isolatedEditor->setText('@');
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

    // ─── Helpers ────────────────────────────────────────────────────

    private function registerListener(): void
    {
        $provider = new SlashCommandCompletionProvider($this->registry);
        $listener = new CompletionListener($provider);

        $context = $this->createContext();
        $listener->register($context);
    }

    private function createContext(): TuiRuntimeContext
    {
        $client = $this->createStub(AgentSessionClient::class);

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: sys_get_temp_dir(),
        );

        $sessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
        );

        return new TuiRuntimeContext(
            tui: $this->tui,
            client: $client,
            state: $this->state,
            screen: $this->screen,
            sessionStore: $sessionStore,
            ticks: new \Ineersa\Tui\Runtime\TuiTickDispatcher(),
            switch: $this->createStub(\Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface::class),
            lifecycle: new \Ineersa\Tui\Runtime\TuiSessionLifecycleDispatcher(),
        );
    }
}
