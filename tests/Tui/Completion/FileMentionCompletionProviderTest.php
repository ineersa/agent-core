<?php

declare(strict_types=1);

namespace Ineersa\Tests\Tui\Completion;

use Ineersa\Tui\Completion\CompletionContext;
use Ineersa\Tui\Completion\FileMentionCompletionProvider;
use Ineersa\Tui\Completion\FileMentionIndexEntryDTO;
use Ineersa\Tui\Completion\FileMentionIndexReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileMentionCompletionProvider::class)]
final class FileMentionCompletionProviderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/editor09-provider-'.getmypid().'-'.hrtime(true);
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    // ── @ token boundary detection ────────────────────────────────

    #[Test]
    public function triggersAtStartOfText(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('src/foo.php', false),
        ]);

        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('@'),
        );

        self::assertCount(1, $suggestions);
        self::assertSame('@src/foo.php', $suggestions[0]->display);
    }

    #[Test]
    public function triggersAfterWhitespace(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('src/foo.php', false),
        ]);

        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('hello @'),
        );

        self::assertCount(1, $suggestions);
        self::assertSame('@src/foo.php', $suggestions[0]->display);
    }

    #[Test]
    public function triggersAfterNewline(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('src/bar.php', false),
        ]);

        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd("line1\n@"),
        );

        self::assertCount(1, $suggestions);
        self::assertSame('@src/bar.php', $suggestions[0]->display);
    }

    #[Test]
    public function doesNotTriggerForEmailPattern(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('src/foo.php', false),
        ]);

        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('email@example.com'),
        );

        self::assertSame([], $suggestions);
    }

    #[Test]
    public function doesNotTriggerForFooAtBar(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('src/foo.php', false),
        ]);

        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('foo@bar'),
        );

        self::assertSame([], $suggestions);
    }

    #[Test]
    public function doesNotTriggerWithoutAtSymbol(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('src/foo.php', false),
        ]);

        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('src/foo'),
        );

        self::assertSame([], $suggestions);
    }

    #[Test]
    public function returnsEmptyWhenIndexIsEmpty(): void
    {
        // Create provider with no index file.
        $reader = new FileMentionIndexReader($this->tmpDir.'/nonexistent.jsonl');
        $provider = new FileMentionCompletionProvider($reader);

        $suggestions = $provider->getSuggestions(CompletionContext::forCursorAtEnd('@'));

        self::assertSame([], $suggestions);
    }

    #[Test]
    public function returnsEmptyForSlashPrefix(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('src/foo.php', false),
        ]);

        // / prefix should NOT trigger file completion.
        $suggestions = $provider->getSuggestions(CompletionContext::forCursorAtEnd('/help'));

        self::assertSame([], $suggestions);
    }

    // ── Quoted @ token ────────────────────────────────────────────

    #[Test]
    public function supportsQuotedAtPrefix(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('some dir/file.php', false),
        ]);

        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('@"'),
        );

        self::assertCount(1, $suggestions);
        self::assertSame('@"some dir/file.php"', $suggestions[0]->display);
    }

    #[Test]
    public function partialQuotedAtPrefixRefines(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('some dir/file.php', false),
            new FileMentionIndexEntryDTO('other/path.txt', false),
        ]);

        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('@"some'),
        );

        self::assertCount(1, $suggestions);
        self::assertSame('@"some dir/file.php"', $suggestions[0]->display);
    }

    // ── Non-fuzzy matching ────────────────────────────────────────

    #[Test]
    public function matchesByPrefix(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('src/Completion/Listener.php', false),
            new FileMentionIndexEntryDTO('src/Completion/Provider.php', false),
            new FileMentionIndexEntryDTO('tests/Test.php', false),
        ]);

        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('@src/Completion'),
        );

        self::assertCount(2, $suggestions);
    }

    #[Test]
    public function matchesByBasename(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('src/Completion/FooListener.php', false),
            new FileMentionIndexEntryDTO('src/Other/BarService.php', false),
        ]);

        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('@Listener'),
        );

        self::assertCount(1, $suggestions);
        self::assertStringContainsString('Listener', $suggestions[0]->display);
    }

    #[Test]
    public function emptyQueryReturnsAllIndexedEntries(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('a.php', false),
            new FileMentionIndexEntryDTO('b.php', false),
            new FileMentionIndexEntryDTO('c.php', false),
        ]);

        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('@'),
        );

        self::assertCount(3, $suggestions);
    }

    // ── Directory suggestions ──────────────────────────────────────

    #[Test]
    public function directorySuggestionsHaveTrailingSlashAndNoTrailingSpace(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('src', true),
            new FileMentionIndexEntryDTO('src/foo.php', false),
        ]);

        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('@src'),
        );

        // Find the directory suggestion by its display trailing slash.
        $dirSuggestion = null;
        foreach ($suggestions as $s) {
            if (str_ends_with(trim($s->display), '/')) {
                $dirSuggestion = $s;
                break;
            }
        }

        self::assertNotNull($dirSuggestion);
        self::assertStringEndsWith('/', $dirSuggestion->insertText);
        self::assertStringNotContainsString(' ', trim($dirSuggestion->insertText, '/'));
        self::assertStringEndsWith('/', $dirSuggestion->display);
    }

    #[Test]
    public function fileSuggestionsHaveTrailingSpace(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('src/foo.php', false),
        ]);

        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('@src'),
        );

        self::assertCount(1, $suggestions);
        self::assertSame('', $suggestions[0]->description);
        self::assertStringEndsWith(' ', $suggestions[0]->insertText);
    }

    // ── Quoting paths with spaces ─────────────────────────────────

    #[Test]
    public function pathsWithSpacesAreQuoted(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('some dir/file.php', false),
        ]);

        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('@some'),
        );

        self::assertCount(1, $suggestions);
        self::assertSame('@"some dir/file.php"', $suggestions[0]->display);
        self::assertStringStartsWith('@"', $suggestions[0]->insertText);
    }

    #[Test]
    public function pathsWithoutSpacesAreNotQuoted(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('src/foo.php', false),
        ]);

        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('@src'),
        );

        self::assertCount(1, $suggestions);
        self::assertStringStartsWith('@src/foo.php', $suggestions[0]->insertText);
    }

    // ── Directory ranking ──────────────────────────────────────────

    #[Test]
    public function directoriesRankBeforeFilesWhenEquallyRelevant(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('src/Tui.php', false),
            new FileMentionIndexEntryDTO('src/Tui', true),
        ]);

        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('@src'),
        );

        // Both should appear, directory should come first due to +10 bonus.
        self::assertCount(2, $suggestions);
        self::assertTrue(
            str_ends_with(trim($suggestions[0]->display), '/'),
            'Directory entry should appear before file entry due to scoring bonus.',
        );
    }

    // ── Whitespace closes unquoted @ token ───────────────────────────

    #[Test]
    public function trailingWhitespaceAfterAtTokenReturnsNoSuggestions(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('src/foo.php', false),
        ]);

        // Whitespace immediately after the @ token closes it.
        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('Hello @Version '),
        );

        self::assertSame([], $suggestions);
    }

    #[Test]
    public function whitespaceWithinAtTokenReturnsNoSuggestions(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('Version.php', false),
        ]);

        // Text after whitespace: "Hello @Version asd asd ..."
        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('Hello @Version asd asd'),
        );

        self::assertSame([], $suggestions);
    }

    #[Test]
    public function atTokenWithNoWhitespaceStillWorks(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('Version.php', false),
        ]);

        // "Hello @Version" — no whitespace after @, should still trigger.
        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('Hello @Version'),
        );

        self::assertCount(1, $suggestions);
        self::assertStringContainsString('Version', $suggestions[0]->display);
    }

    #[Test]
    public function atTokenWithTabsReturnsNoSuggestions(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('src/foo.php', false),
        ]);

        // Tab within the @ token text closes it.
        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd("@src\tmore"),
        );

        self::assertSame([], $suggestions);
    }

    #[Test]
    public function leadingAtStillTriggers(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('composer.json', false),
        ]);

        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('@'),
        );

        self::assertNotEmpty($suggestions);
    }

    // ── Quoted @ token closes on closing quote ───────────────────────

    #[Test]
    public function quotedTokenWithClosingQuoteReturnsNoSuggestions(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('some dir/file.php', false),
        ]);

        // The closing quote completes the token.
        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('@"some dir/file.php"'),
        );

        self::assertSame([], $suggestions);
    }

    #[Test]
    public function quotedTokenWithoutClosingQuoteStillTriggers(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('some dir/file.php', false),
        ]);

        // No closing quote — token still active.
        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('@"some dir'),
        );

        self::assertNotEmpty($suggestions);
    }

    // ── Multiple @ in text uses the last one ───────────────────────

    #[Test]
    public function lastAtSymbolIsUsedForCompletion(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('src/bar.php', false),
        ]);

        // Two @ symbols; the last one wins.
        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('email@host.com hello @bar'),
        );

        self::assertCount(1, $suggestions);
        self::assertStringContainsString('bar', $suggestions[0]->display);
    }

    // ── Suggestions are capped ─────────────────────────────────────

    #[Test]
    public function suggestionsAreCapped(): void
    {
        $entries = [];
        for ($i = 0; $i < 100; ++$i) {
            $entries[] = new FileMentionIndexEntryDTO(
                'file_'.str_pad((string) $i, 3, '0', \STR_PAD_LEFT).'.php',
                false,
            );
        }

        $provider = $this->providerWithEntries($entries);
        $suggestions = $provider->getSuggestions(CompletionContext::forCursorAtEnd('@'));

        // Should be capped at 30 (FileMentionCompletionProvider::MAX_SUGGESTIONS).
        self::assertLessThanOrEqual(30, \count($suggestions));
        self::assertGreaterThan(0, \count($suggestions));
    }

    // ── Replacement range ──────────────────────────────────────────

    #[Test]
    public function replacementRangeCoversAtToken(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('src/foo.php', false),
        ]);

        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('hello @src'),
        );

        self::assertCount(1, $suggestions);
        self::assertSame(6, $suggestions[0]->replacementStart); // position of @
        self::assertSame(4, $suggestions[0]->replacementLength); // '@src' length
    }

    // ── Case-insensitive matching ──────────────────────────────────

    #[Test]
    public function matchingIsCaseInsensitive(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('src/FooBar.php', false),
        ]);

        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('@foobar'),
        );

        self::assertCount(1, $suggestions);
    }

    // ── Quoted @ preserves prefix ──────────────────────────────────

    #[Test]
    public function quotedPathSuggestionPreservesAtPrefixWhenApplied(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('some dir/file.php', false),
        ]);

        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('@"'),
        );

        self::assertCount(1, $suggestions);

        // Simulate how CompletionListener::applySuggestion() applies the
        // suggestion: substr_replace with the insertion text, replacement
        // start, and replacement length.
        $currentText = '@"';
        $applied = substr_replace(
            $currentText,
            $suggestions[0]->insertText,
            $suggestions[0]->replacementStart,
            $suggestions[0]->replacementLength,
        );

        // The applied text must start with @ so the @ token remains visible.
        self::assertStringStartsWith('@', $applied);
        // Should look like @"some dir/file.php" with trailing space.
        self::assertSame('@"some dir/file.php" ', $applied);
    }

    #[Test]
    public function quotedDirectorySuggestionIncludesAtPrefixAndTrailingSlash(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('some dir', true),
        ]);

        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('@"some'),
        );

        self::assertCount(1, $suggestions);
        self::assertSame('', $suggestions[0]->description);

        // Insert text starts with @" and ends with / (no trailing space for dirs).
        self::assertStringStartsWith('@"', $suggestions[0]->insertText);
        self::assertStringEndsWith('/', $suggestions[0]->insertText);

        // Verify full application preserves the @ prefix.
        $currentText = '@"some';
        $applied = substr_replace(
            $currentText,
            $suggestions[0]->insertText,
            $suggestions[0]->replacementStart,
            $suggestions[0]->replacementLength,
        );
        self::assertStringStartsWith('@', $applied);
        self::assertStringEndsWith('/', $applied);
    }

    // ── Expanded path quoting rules ─────────────────────────────────

    #[Test]
    public function pathsWithParenthesesAreQuoted(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('file(foo).php', false),
        ]);

        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('@file'),
        );

        self::assertCount(1, $suggestions);
        self::assertStringStartsWith('@"', $suggestions[0]->insertText);
        self::assertStringEndsWith('" ', $suggestions[0]->insertText);
        self::assertStringContainsString('file(foo).php', $suggestions[0]->insertText);
    }

    #[Test]
    public function pathsWithDollarSignAreQuoted(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('file$var.txt', false),
        ]);

        $suggestions = $provider->getSuggestions(
            CompletionContext::forCursorAtEnd('@file'),
        );

        self::assertCount(1, $suggestions);
        self::assertStringStartsWith('@"', $suggestions[0]->insertText);
    }

    // ─── Helpers ────────────────────────────────────────────────────

    /**
     * @param list<FileMentionIndexEntryDTO> $entries
     */
    private function providerWithEntries(array $entries): FileMentionCompletionProvider
    {
        // Write a temporary index file so the reader can load it.
        $indexPath = $this->tmpDir.'/index.jsonl';
        $handle = fopen($indexPath, 'wb');
        foreach ($entries as $entry) {
            fwrite($handle, json_encode([
                'path' => $entry->path,
                'dir' => $entry->isDirectory,
            ], \JSON_UNESCAPED_SLASHES)."\n");
        }
        fclose($handle);

        $reader = new FileMentionIndexReader($indexPath);

        return new FileMentionCompletionProvider($reader);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $fileinfo) {
            $op = $fileinfo->isDir() ? 'rmdir' : 'unlink';
            $op($fileinfo->getRealPath());
        }
        rmdir($dir);
    }
}
