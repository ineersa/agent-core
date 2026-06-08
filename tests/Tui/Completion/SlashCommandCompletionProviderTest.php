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

        $this->assertNotEmpty($suggestions);
        // Built-in commands should appear
        $displays = array_map(static fn (CompletionSuggestion $s) => $s->display, $suggestions);
        $this->assertContains('/clear', $displays);
        $this->assertContains('/exit', $displays);
        $this->assertContains('/help', $displays);
    }

    #[Test]
    public function returnsSuggestionsForPartialSlashPrefix(): void
    {
        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('/he'));

        $this->assertNotEmpty($suggestions);
        $displays = array_map(static fn (CompletionSuggestion $s) => $s->display, $suggestions);
        $this->assertContains('/help', $displays);
        $this->assertNotContains('/clear', $displays);
        $this->assertNotContains('/exit', $displays);
    }

    #[Test]
    public function slashAloneShowsAllCommands(): void
    {
        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('/'));

        // All built-in commands (clear, exit, help) should appear
        $this->assertGreaterThanOrEqual(3, \count($suggestions));
    }

    #[Test]
    public function slashAfterNewlineDoesNotTrigger(): void
    {
        // Slash after a newline is not at text start — no completion.
        $this->assertSame([], $this->provider->getSuggestions(CompletionContext::forCursorAtEnd("hello\n/")));
    }

    #[Test]
    public function slashAfterNewlineWithPrefixDoesNotTrigger(): void
    {
        $this->assertSame([], $this->provider->getSuggestions(CompletionContext::forCursorAtEnd("hello\n/ex")));
    }

    #[Test]
    public function slashAfterNewlineHasNoSuggestions(): void
    {
        // Text with a slash after newline — still after a newline, not at start.
        $this->assertSame([], $this->provider->getSuggestions(CompletionContext::forCursorAtEnd("/help\n/")));
    }

    // ── Non-slash context returns empty ──────────────────────────────

    #[Test]
    public function returnsEmptyForNonSlashText(): void
    {
        $this->assertSame([], $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('hello')));
    }

    #[Test]
    public function returnsEmptyForEmptyString(): void
    {
        $this->assertSame([], $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('')));
    }

    #[Test]
    public function returnsEmptyForMidLineSlash(): void
    {
        // Slash that is not at line start — no completion trigger
        $this->assertSame([], $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('hello /he')));
    }

    #[Test]
    public function returnsEmptyForLeadingSpaces(): void
    {
        // Spaces before "/" mean it's not at column 0
        $this->assertSame([], $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('  /he')));
    }

    #[Test]
    public function returnsEmptyForEscapedSlash(): void
    {
        // "//" is an escaped slash, not a command
        $this->assertSame([], $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('//')));
    }

    #[Test]
    public function returnsEmptyForEscapedSlashAfterNewline(): void
    {
        $this->assertSame([], $this->provider->getSuggestions(CompletionContext::forCursorAtEnd("hello\n//")));
    }

    // ── Alias matching ──────────────────────────────────────────────

    #[Test]
    public function aliasPrefixSuggestsCanonicalCommand(): void
    {
        // /q is an alias for /exit
        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('/q'));

        $this->assertNotEmpty($suggestions);
        $displays = array_map(static fn (CompletionSuggestion $s) => $s->display, $suggestions);
        $this->assertContains('/exit', $displays);
        $this->assertNotContains('/q', $displays); // Display uses canonical name

        // insertText must be canonical: "/exit "
        $exitSuggestion = $this->findByDisplay($suggestions, '/exit');
        $this->assertNotNull($exitSuggestion);
        $this->assertSame('/exit ', $exitSuggestion->insertText);
    }

    #[Test]
    public function aliasPrefixPartialMatch(): void
    {
        // /cl matches /clear by canonical name prefix (not via alias cls).
        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('/cl'));

        $this->assertNotEmpty($suggestions);
        $displays = array_map(static fn (CompletionSuggestion $s) => $s->display, $suggestions);
        $this->assertContains('/clear', $displays);
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
        $this->assertCount(1, $exitSuggestions);
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
            $this->createStub(SlashCommandHandler::class),
        );

        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('/m'));

        $this->assertNotEmpty($suggestions);
        $displays = array_map(static fn (CompletionSuggestion $s) => $s->display, $suggestions);
        $this->assertContains('/model', $displays);
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
            $this->createStub(SlashCommandHandler::class),
        );

        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('/'));

        $displays = array_map(static fn (CompletionSuggestion $s) => $s->display, $suggestions);
        $this->assertContains('/custom', $displays);
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
            $this->createStub(SlashCommandHandler::class),
        );

        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('/m'));

        $displays = array_map(static fn (CompletionSuggestion $s) => $s->display, $suggestions);
        $this->assertContains('/model', $displays);
    }

    // ── Suggestion metadata ──────────────────────────────────────────

    #[Test]
    public function suggestionsIncludeDescription(): void
    {
        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('/he'));

        $help = $this->findByDisplay($suggestions, '/help');
        $this->assertNotNull($help);
        $this->assertNotEmpty($help->description);
    }

    #[Test]
    public function suggestionInsertTextIncludesTrailingSpaceForCommand(): void
    {
        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('/cle'));

        $clear = $this->findByDisplay($suggestions, '/clear');
        $this->assertNotNull($clear);
        $this->assertSame('/clear ', $clear->insertText);
    }

    #[Test]
    public function replacementRangeCoversSlashAndPrefix(): void
    {
        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('/he'));

        $help = $this->findByDisplay($suggestions, '/help');
        $this->assertNotNull($help);
        // /he at pos 0, replacement of "/he" (3 bytes)
        $this->assertSame(0, $help->replacementStart);
        $this->assertSame(3, $help->replacementLength);
    }

    #[Test]
    public function slashAfterNewlineHasNoReplacementRange(): void
    {
        // Newline slash does not trigger — replacement range is N/A.
        $this->assertSame([], $this->provider->getSuggestions(CompletionContext::forCursorAtEnd("hello\n/ex")));
    }

    // ── Deterministic ordering ──────────────────────────────────────

    #[Test]
    public function suggestionsArePreservedInRegistryOrder(): void
    {
        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('/'));

        $displays = array_map(static fn (CompletionSuggestion $s) => $s->display, $suggestions);
        // Registry sorts alphabetically by canonical name: clear → exit → help
        $expected = ['/clear', '/exit', '/help'];
        $this->assertSame($expected, $displays);
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
        $this->assertNotEmpty($suggestions);
        $displays = array_map(static fn (CompletionSuggestion $s) => $s->display, $suggestions);
        $this->assertContains('/help', $displays);
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
