<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Completion;

use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Completion\CompletionContext;
use Ineersa\Tui\Completion\CompletionSuggestion;
use Ineersa\Tui\Completion\SlashCommandCompletionProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SlashCommandCompletionProvider::class)]
final class SlashCommandCompletionProviderTest extends TestCase
{
    private SlashCommandRegistry $registry;
    private SlashCommandCompletionProvider $provider;

    protected function setUp(): void
    {
        $this->registry = new SlashCommandRegistry();
        $this->provider = new SlashCommandCompletionProvider($this->registry);
    }

    // ── Slash context detection ──────────────────────────────────────

    #[Test]
    public function returnsSuggestionsForSlashAtTextStart(): void
    {
        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('/'));

        self::assertNotEmpty($suggestions);
        // Built-in commands should appear
        $displays = array_map(static fn (CompletionSuggestion $s) => $s->display, $suggestions);
        self::assertContains('/clear', $displays);
        self::assertContains('/exit', $displays);
        self::assertContains('/help', $displays);
    }

    #[Test]
    public function returnsSuggestionsForPartialSlashPrefix(): void
    {
        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('/he'));

        self::assertNotEmpty($suggestions);
        $displays = array_map(static fn (CompletionSuggestion $s) => $s->display, $suggestions);
        self::assertContains('/help', $displays);
        self::assertNotContains('/clear', $displays);
        self::assertNotContains('/exit', $displays);
    }

    #[Test]
    public function slashAloneShowsAllCommands(): void
    {
        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('/'));

        // All built-in commands (clear, exit, help) should appear
        self::assertGreaterThanOrEqual(3, \count($suggestions));
    }

    #[Test]
    public function slashAfterNewlineDoesNotTrigger(): void
    {
        // Slash after a newline is not at text start — no completion.
        self::assertSame([], $this->provider->getSuggestions(CompletionContext::forCursorAtEnd("hello\n/")));
    }

    #[Test]
    public function slashAfterNewlineWithPrefixDoesNotTrigger(): void
    {
        self::assertSame([], $this->provider->getSuggestions(CompletionContext::forCursorAtEnd("hello\n/ex")));
    }

    #[Test]
    public function slashAfterNewlineHasNoSuggestions(): void
    {
        // Text with a slash after newline — still after a newline, not at start.
        self::assertSame([], $this->provider->getSuggestions(CompletionContext::forCursorAtEnd("/help\n/")));
    }

    // ── Non-slash context returns empty ──────────────────────────────

    #[Test]
    public function returnsEmptyForNonSlashText(): void
    {
        self::assertSame([], $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('hello')));
    }

    #[Test]
    public function returnsEmptyForEmptyString(): void
    {
        self::assertSame([], $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('')));
    }

    #[Test]
    public function returnsEmptyForMidLineSlash(): void
    {
        // Slash that is not at line start — no completion trigger
        self::assertSame([], $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('hello /he')));
    }

    #[Test]
    public function returnsEmptyForLeadingSpaces(): void
    {
        // Spaces before "/" mean it's not at column 0
        self::assertSame([], $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('  /he')));
    }

    #[Test]
    public function returnsEmptyForEscapedSlash(): void
    {
        // "//" is an escaped slash, not a command
        self::assertSame([], $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('//')));
    }

    #[Test]
    public function returnsEmptyForEscapedSlashAfterNewline(): void
    {
        self::assertSame([], $this->provider->getSuggestions(CompletionContext::forCursorAtEnd("hello\n//")));
    }

    // ── Alias matching ──────────────────────────────────────────────

    #[Test]
    public function aliasPrefixSuggestsCanonicalCommand(): void
    {
        // /q is an alias for /exit
        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('/q'));

        self::assertNotEmpty($suggestions);
        $displays = array_map(static fn (CompletionSuggestion $s) => $s->display, $suggestions);
        self::assertContains('/exit', $displays);
        self::assertNotContains('/q', $displays); // Display uses canonical name

        // insertText must be canonical: "/exit "
        $exitSuggestion = $this->findByDisplay($suggestions, '/exit');
        self::assertNotNull($exitSuggestion);
        self::assertSame('/exit ', $exitSuggestion->insertText);
    }

    #[Test]
    public function aliasPrefixPartialMatch(): void
    {
        // /cl matches /clear by canonical name prefix (not via alias cls).
        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('/cl'));

        self::assertNotEmpty($suggestions);
        $displays = array_map(static fn (CompletionSuggestion $s) => $s->display, $suggestions);
        self::assertContains('/clear', $displays);
    }

    #[Test]
    public function multipleAliasesDoNotDuplicateCanonical(): void
    {
        // /exit has aliases: quit, q
        // Both "q" and "quit" match "/q" prefix
        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('/q'));

        // /exit should appear exactly once
        $exitSuggestions = array_filter(
            $suggestions,
            static fn (CompletionSuggestion $s) => '/exit' === $s->display,
        );
        self::assertCount(1, $exitSuggestions);
    }

    // ── Runtime registration ─────────────────────────────────────────

    #[Test]
    public function includesRuntimeRegisteredCommands(): void
    {
        // Simulate ModelControlListener registering /model at runtime
        $this->registry->register(
            new CommandMetadata(
                name: 'model',
                aliases: ['m'],
                description: 'Interactive model selection',
                usage: '/model',
            ),
            self::createStub(SlashCommandHandler::class),
        );

        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('/m'));

        self::assertNotEmpty($suggestions);
        $displays = array_map(static fn (CompletionSuggestion $s) => $s->display, $suggestions);
        self::assertContains('/model', $displays);
    }

    #[Test]
    public function runtimeRegisteredCommandsAppearWithEmptyPrefix(): void
    {
        $this->registry->register(
            new CommandMetadata(
                name: 'custom',
                aliases: [],
                description: 'Custom command',
                usage: '/custom',
            ),
            self::createStub(SlashCommandHandler::class),
        );

        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('/'));

        $displays = array_map(static fn (CompletionSuggestion $s) => $s->display, $suggestions);
        self::assertContains('/custom', $displays);
    }

    #[Test]
    public function runtimeRegisteredAliasesWork(): void
    {
        $this->registry->register(
            new CommandMetadata(
                name: 'model',
                aliases: ['m'],
                description: 'Interactive model selection',
                usage: '/model',
            ),
            self::createStub(SlashCommandHandler::class),
        );

        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('/m'));

        $displays = array_map(static fn (CompletionSuggestion $s) => $s->display, $suggestions);
        self::assertContains('/model', $displays);
    }

    // ── Suggestion metadata ──────────────────────────────────────────

    #[Test]
    public function suggestionsIncludeDescription(): void
    {
        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('/he'));

        $help = $this->findByDisplay($suggestions, '/help');
        self::assertNotNull($help);
        self::assertNotEmpty($help->description);
    }

    #[Test]
    public function suggestionInsertTextIncludesTrailingSpaceForCommand(): void
    {
        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('/cle'));

        $clear = $this->findByDisplay($suggestions, '/clear');
        self::assertNotNull($clear);
        self::assertSame('/clear ', $clear->insertText);
    }

    #[Test]
    public function replacementRangeCoversSlashAndPrefix(): void
    {
        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('/he'));

        $help = $this->findByDisplay($suggestions, '/help');
        self::assertNotNull($help);
        // /he at pos 0, replacement of "/he" (3 bytes)
        self::assertSame(0, $help->replacementStart);
        self::assertSame(3, $help->replacementLength);
    }

    #[Test]
    public function slashAfterNewlineHasNoReplacementRange(): void
    {
        // Newline slash does not trigger — replacement range is N/A.
        self::assertSame([], $this->provider->getSuggestions(CompletionContext::forCursorAtEnd("hello\n/ex")));
    }

    // ── Deterministic ordering ──────────────────────────────────────

    #[Test]
    public function suggestionsArePreservedInRegistryOrder(): void
    {
        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('/'));

        $displays = array_map(static fn (CompletionSuggestion $s) => $s->display, $suggestions);
        // Registry sorts alphabetically by canonical name: clear → exit → help → hotkeys
        $expected = ['/clear', '/exit', '/help', '/hotkeys'];
        self::assertSame($expected, $displays);
    }

    // ── Cursor offset MVP behaviour ───────────────────────────────

    #[Test]
    public function midTextCursorStillOperatesCursorAtEndForMvp(): void
    {
        // EDITOR-08 only triggers when text starts with "/".
        // Non-start slash contexts are ignored until live cursor
        // state is exposed in a future phase.
        $context = new CompletionContext('/he', 1); // cursor between '/' and 'h'
        $suggestions = $this->provider->getSuggestions($context);

        // MVP still sees the full prefix "he" → suggests /help
        self::assertNotEmpty($suggestions);
        $displays = array_map(static fn (CompletionSuggestion $s) => $s->display, $suggestions);
        self::assertContains('/help', $displays);
    }

    // ── Helpers ────────────────────────────────────────────────────

    /** @param list<CompletionSuggestion> $suggestions */
    private function findByDisplay(array $suggestions, string $display): ?CompletionSuggestion
    {
        foreach ($suggestions as $s) {
            if ($display === $s->display) {
                return $s;
            }
        }

        return null;
    }
}
