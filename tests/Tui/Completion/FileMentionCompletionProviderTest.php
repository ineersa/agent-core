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

        $this->assertCount(1, $suggestions);
        $this->assertSame('@src/foo.php', $suggestions[0]->display);
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

        $this->assertCount(1, $suggestions);
        $this->assertSame('@src/foo.php', $suggestions[0]->display);
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

        $this->assertCount(1, $suggestions);
        $this->assertSame('@src/bar.php', $suggestions[0]->display);
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

        $this->assertSame([], $suggestions);
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

        $this->assertSame([], $suggestions);
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

        $this->assertSame([], $suggestions);
    }

    #[Test]
    public function returnsEmptyWhenIndexIsEmpty(): void
    {
        // Create provider with no index file.
        $reader = new FileMentionIndexReader($this->tmpDir.'/nonexistent.jsonl');
        $provider = new FileMentionCompletionProvider($reader);

        $suggestions = $provider->getSuggestions(CompletionContext::forCursorAtEnd('@'));

        $this->assertSame([], $suggestions);
    }

    #[Test]
    public function returnsEmptyForSlashPrefix(): void
    {
        $provider = $this->providerWithEntries([
            new FileMentionIndexEntryDTO('src/foo.php', false),
        ]);

        // / prefix should NOT trigger file completion.
        $suggestions = $provider->getSuggestions(CompletionContext::forCursorAtEnd('/help'));

        $this->assertSame([], $suggestions);
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

        $this->assertCount(1, $suggestions);
        $this->assertSame('@"some dir/file.php"', $suggestions[0]->display);
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

        $this->assertCount(1, $suggestions);
        $this->assertSame('@"some dir/file.php"', $suggestions[0]->display);
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

        $this->assertCount(2, $suggestions);
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

        $this->assertCount(1, $suggestions);
        $this->assertStringContainsString('Listener', $suggestions[0]->display);
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

        $this->assertCount(3, $suggestions);
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

        // Find the directory suggestion.
        $dirSuggestion = null;
        foreach ($suggestions as $s) {
            if ($s->description === 'directory') {
                $dirSuggestion = $s;
                break;
            }
        }

        $this->assertNotNull($dirSuggestion);
        $this->assertStringEndsWith('/', $dirSuggestion->insertText);
        $this->assertStringNotContainsString(' ', trim($dirSuggestion->insertText, '/'));
        $this->assertStringEndsWith('/', $dirSuggestion->display);
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

        $this->assertCount(1, $suggestions);
        $this->assertSame('file', $suggestions[0]->description);
        $this->assertStringEndsWith(' ', $suggestions[0]->insertText);
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

        $this->assertCount(1, $suggestions);
        $this->assertSame('@"some dir/file.php"', $suggestions[0]->display);
        $this->assertStringStartsWith('@"', $suggestions[0]->insertText);
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

        $this->assertCount(1, $suggestions);
        $this->assertStringStartsWith('@src/foo.php', $suggestions[0]->insertText);
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
        $this->assertCount(2, $suggestions);
        $this->assertTrue(
            $suggestions[0]->isDirectory ?? $this->dirDescription($suggestions[0]),
        );
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

        $this->assertCount(1, $suggestions);
        $this->assertStringContainsString('bar', $suggestions[0]->display);
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
        $this->assertLessThanOrEqual(30, \count($suggestions));
        $this->assertGreaterThan(0, \count($suggestions));
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

        $this->assertCount(1, $suggestions);
        $this->assertSame(6, $suggestions[0]->replacementStart); // position of @
        $this->assertSame(4, $suggestions[0]->replacementLength); // '@src' length
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

        $this->assertCount(1, $suggestions);
    }

    // ─── Helpers ────────────────────────────────────────────────────

    /**
     * @param list<FileMentionIndexEntryDTO> $entries
     */
    private function providerWithEntries(array $entries): FileMentionCompletionProvider
    {
        // Write a temporary index file so the reader can load it.
        $indexPath = $this->tmpDir.'/index.jsonl';
        $handle = fopen($indexPath, 'w');
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

    private function dirDescription(\Ineersa\Tui\Completion\CompletionSuggestion $s): bool
    {
        return $s->description === 'directory';
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
