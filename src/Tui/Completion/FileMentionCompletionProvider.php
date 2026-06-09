<?php

declare(strict_types=1);

namespace Ineersa\Tui\Completion;

/**
 * Completion provider for @ file/path mentions.
 *
 * Detects an active @ token at a token boundary and returns
 * path suggestions from the in-memory file mention index.
 *
 * Matching is non-fuzzy: prefix matches on path/basename are
 * preferred over substring contains.  Directories get a small
 * bonus so they appear before files.  Suggestions are capped.
 *
 * Quoted path support: when the user types @"..., the raw query
 * is extracted from inside the quotes and inserted values preserve
 * the quoted context.
 *
 * The provider only reads from the cached index — it never
 * hits the filesystem directly.
 */
final readonly class FileMentionCompletionProvider implements CompletionProvider
{
    private const int MAX_SUGGESTIONS = 30;

    public function __construct(
        private FileMentionIndexReader $indexReader,
    ) {
    }

    public function getSuggestions(CompletionContext $context): array
    {
        $token = $this->extractAtToken($context->text);

        if (null === $token) {
            // No active @ token — return empty and let other
            // providers (e.g. slash commands) handle the context.
            return [];
        }

        $query = $token->query;
        $entries = $this->indexReader->getEntries();
        $pathsLower = $this->indexReader->getPathsLower();
        $basenamesLower = $this->indexReader->getBasenamesLower();

        if ([] === $entries) {
            return [];
        }

        $queryLower = mb_strtolower($query);

        // ── Rank entries against the query ──────────────────────
        $scored = [];

        foreach ($entries as $i => $entry) {
            $pathLower = $pathsLower[$i];
            $basenameLower = $basenamesLower[$i];
            $score = 0;

            // Path prefix match
            if ('' === $queryLower || str_starts_with($pathLower, $queryLower)) {
                $score += 100;
            }

            // Basename prefix match
            if ('' !== $queryLower && str_starts_with($basenameLower, $queryLower)) {
                $score += 80;
            }

            // Basename contains
            if ('' !== $queryLower && str_contains($basenameLower, $queryLower)) {
                $score += 40;
            }

            // Path contains
            if ('' !== $queryLower && $pathLower !== $basenameLower && str_contains($pathLower, $queryLower)) {
                $score += 20;
            }

            // Directory bonus
            if ($entry->isDirectory) {
                $score += 10;
            }

            if ($score > 0) {
                $scored[] = [$score, $entry];
            }
        }

        // ── Sort by score descending, stable ────────────────────
        usort($scored, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        // ── Cap and build suggestions ───────────────────────────
        $suggestions = [];
        foreach ($scored as $i => [$score, $entry]) {
            if ($i >= self::MAX_SUGGESTIONS) {
                break;
            }

            $suggestions[] = $this->buildSuggestion($entry, $token);
        }

        return $suggestions;
    }

    // ─── Private helpers ────────────────────────────────────────────

    private function buildSuggestion(
        FileMentionIndexEntryDTO $entry,
        AtTokenContext $token,
    ): CompletionSuggestion {
        $displayPath = $entry->path;
        $needsQuoting = $this->needsPathQuoting($displayPath);

        // Build the editor insertion text.  The replacement range
        // covers the @ token plus any following text, so the inserted
        // text must include the @ prefix (and quotes when needed).

        $suffix = $entry->isDirectory ? '/' : ' ';

        if ($needsQuoting) {
            $insertText = '@"'.$displayPath.'"'.$suffix;
        } else {
            $insertText = '@'.$displayPath.$suffix;
        }

        // When the user typed @"..., the token's replacement range
        // already covers the @ and the opening quote.  The insertText
        // above would double-count the @ and quote, so we strip the
        // prefix that is already in the replacement range.
        // This is only for the quoted token case where the opening
        // quote has been typed.
        if ($token->isQuoted) {
            // The replacement range starts at @ and includes the
            // opening ".  The insert text above includes '@" ---
            // strip the duplicated @ prefix to avoid double-@.
            if (str_starts_with($insertText, '@')) {
                $insertText = substr($insertText, 1);
            }
        }

        if ($needsQuoting) {
            $displayPath = '"'.$displayPath.'"';
        }

        $display = '@'.$displayPath.($entry->isDirectory ? '/' : '');

        return new CompletionSuggestion(
            display: $display,
            insertText: $insertText,
            description: $entry->isDirectory ? 'directory' : 'file',
            replacementStart: $token->replacementStart,
            replacementLength: $token->replacementLength,
        );
    }

    /**
     * Extract the active @ token from editor text.
     *
     * Returns null when there is no active @ token at a token
     * boundary (e.g. email@example.com is not a token).
     *
     * Supported shapes:
     *
     *   @         — empty query, show all
     *
     *   @src      — query = "src"
     *   @"path    — quoted, query = "path"
     *   hello @src — token after whitespace
     */
    private function extractAtToken(string $text): ?AtTokenContext
    {
        $lastAt = strrpos($text, '@');

        if (false === $lastAt) {
            return null;
        }

        // Check token boundary: @ must be at start of text or
        // preceded by a whitespace/tab character.
        if ($lastAt > 0 && !$this->isTokenBoundary($text[$lastAt - 1])) {
            return null;
        }

        $afterAt = substr($text, $lastAt + 1);
        $isQuoted = false;
        $query = '';

        if (str_starts_with($afterAt, '"')) {
            // Quoted token: @"query"
            $isQuoted = true;
            $query = substr($afterAt, 1);
        } else {
            $query = $afterAt;
        }

        return new AtTokenContext(
            query: $query,
            replacementStart: $lastAt,
            replacementLength: \strlen($text) - $lastAt,
            isQuoted: $isQuoted,
            rawText: $text,
        );
    }

    /**
     * Whether the given character is a token boundary before @.
     */
    private function isTokenBoundary(string $char): bool
    {
        return ' ' === $char || "\t" === $char || "\n" === $char;
    }

    /**
     * Whether a path needs quoting (contains spaces or special chars).
     */
    private function needsPathQuoting(string $path): bool
    {
        return str_contains($path, ' ');
    }
}

/**
 * Internal value object for the currently active @ token.
 *
 * @internal public only for testability; consumers outside
 *           FileMentionCompletionProvider should not depend on this
 */
final readonly class AtTokenContext
{
    /**
     * @param string $query             Raw query text after @ and optional opening quote
     * @param int    $replacementStart  Byte offset of @ in the editor text
     * @param int    $replacementLength Number of bytes from @ to end of text
     * @param bool   $isQuoted          Whether the token is quoted (@"...")
     * @param string $rawText           Full editor text at time of extraction
     */
    public function __construct(
        public string $query,
        public int $replacementStart,
        public int $replacementLength,
        public bool $isQuoted,
        public string $rawText,
    ) {
    }
}
