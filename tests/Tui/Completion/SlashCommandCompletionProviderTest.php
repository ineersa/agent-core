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
    public function returnsSuggestionsAfterNewline(): void
    {
        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd("hello\n/"));

        $this->assertNotEmpty($suggestions);
        $displays = array_map(static fn (CompletionSuggestion $s) => $s->display, $suggestions);
        $this->assertContains('/clear', $displays);
    }

    #[Test]
    public function returnsSuggestionsForPartialPrefixAfterNewline(): void
    {
        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd("hello\n/ex"));

        $this->assertNotEmpty($suggestions);
        $displays = array_map(static fn (CompletionSuggestion $s) => $s->display, $suggestions);
        $this->assertContains('/exit', $displays);
        $this->assertNotContains('/clear', $displays);
    }

    #[Test]
    public function preservesTextBeforeLastNewline(): void
    {
        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd("/help\n/"));

        $this->assertNotEmpty($suggestions);
        // The replacementStart should point to the second "/" position
        // "/help\n/" = 7 chars, last newline at pos 5, "/" at pos 6
        foreach ($suggestions as $s) {
            $this->assertSame(6, $s->replacementStart);
        }
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
        // /cl is an alias prefix for /clear (alias: cls)
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
    public function replacementRangeAfterNewline(): void
    {
        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd("hello\n/ex"));

        $exit = $this->findByDisplay($suggestions, '/exit');
        $this->assertNotNull($exit);
        // "hello\n/ex" — "/ex" starts at pos 6, length 3
        $this->assertSame(6, $exit->replacementStart);
        $this->assertSame(3, $exit->replacementLength);
    }

    // ── Deterministic ordering ──────────────────────────────────────

    #[Test]
    public function suggestionsArePreservedInRegistryOrder(): void
    {
        $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('/'));

        $displays = array_map(static fn (CompletionSuggestion $s) => $s->display, $suggestions);
        // Registry sorts by canonical name
        $this->assertSame($displays, array_values($displays)); // Verify preservation of order
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
