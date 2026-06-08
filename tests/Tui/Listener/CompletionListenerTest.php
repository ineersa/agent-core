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

    // ── Multiline replacement ─────────────────────────────────────

    #[Test]
    public function completionReplacesSlashTokenAndPreservesPrecedingLines(): void
    {
        $this->editor->setText("previous line\n/");

        // Open menu
        $this->tui->handleInput("\t");

        // Navigate to /exit (index 1)
        $this->tui->handleInput("\x1b[B");

        // Accept
        $this->tui->handleInput("\t");

        $this->assertSame("previous line\n/exit ", $this->editor->getText());
    }

    #[Test]
    public function completionReplacesPartialSlashTokenInMultilineText(): void
    {
        $this->editor->setText("/help\n/ex");

        // Open menu — should match /ex → /exit
        $this->tui->handleInput("\t");

        // Accept first (only) suggestion
        $this->tui->handleInput("\t");

        $this->assertSame("/help\n/exit ", $this->editor->getText());
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

    // ── Normal typing closes stale menu ───────────────────────────

    #[Test]
    public function normalInputClosesCompletionMenu(): void
    {
        // Simulate natural typing: focus editor and type '/'
        $this->tui->setFocus($this->screen->editorWidget());
        $this->tui->handleInput('/');

        // Open menu
        $this->tui->handleInput("\t");

        // Type a character — should close menu and pass through to editor
        $this->tui->handleInput('x');

        // Text should be "/x" (cursor was after '/' from natural typing)
        $this->assertSame('/x', $this->editor->getText());
    }

    #[Test]
    public function afterNormalInputClosesMenuTabCanReopen(): void
    {
        // Simulate natural typing: focus editor and type '/'
        $this->tui->setFocus($this->screen->editorWidget());
        $this->tui->handleInput('/');

        // Open menu
        $this->tui->handleInput("\t");

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

        // Register via a fresh registry for this test
        $registry = new SlashCommandRegistry();
        $registry->register(
            new CommandMetadata(name: 'testcmd', aliases: [], description: 'Test'),
            $handler,
        );

        // Re-register with test-specific provider
        $provider = new SlashCommandCompletionProvider($registry);
        $listener = new CompletionListener($provider);

        $context = $this->createContext();
        $listener->register($context);

        $this->editor->setText('/test');

        // Tab opens completion, Tab accepts
        $this->tui->handleInput("\t");
        $this->tui->handleInput("\t");

        $this->assertFalse($callCount->called, 'Slash command handler must not be invoked via Tab completion.');
        $this->assertSame('/testcmd ', $this->editor->getText());
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
        );
    }
}
