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
 * the @ prefix and quoted context — the replacement range already
 * covers the @ and opening quote, so no stripping is needed.
 *
 * The provider only reads from the cached index — it never
 * hits the filesystem directly.
 */
final readonly class FileMentionCompletionProvider implements CompletionProvider
{
    private const int MAX_SUGGESTIONS = 30;

    /**
     * Characters that are safe in a path without quoting.
     * Any character NOT in this whitelist triggers @"..." quoting
     * in the inserted suggestion.
     */
    private const string SAFE_PATH_CHARS = 'A-Za-z0-9._@%+=:,/\-';

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
        // No stripping is performed for quoted tokens — the replacement
        // range already covers the @ and opening quote, and the
        // insertText below starts with @" which replaces them correctly.

        $suffix = $entry->isDirectory ? '/' : ' ';

        if ($needsQuoting) {
            $insertText = '@"'.$displayPath.'"'.$suffix;
        } else {
            $insertText = '@'.$displayPath.$suffix;
        }

        if ($needsQuoting) {
            $displayPath = '"'.$displayPath.'"';
        }

        $display = '@'.$displayPath.($entry->isDirectory ? '/' : '');

        return new CompletionSuggestion(
            display: $display,
            insertText: $insertText,
            description: '',
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
        // preceded by a whitespace/tab/newline character.
        if ($lastAt > 0 && !$this->isTokenBoundary($text[$lastAt - 1])) {
            return null;
        }

        $afterAt = substr($text, $lastAt + 1);

        if (str_starts_with($afterAt, '"')) {
            // Quoted token: strip opening quote; if a closing quote is
            // already present before cursor/end, the token is complete
            // and should not trigger completion (cursor-at-end MVP).
            $inner = substr($afterAt, 1);
            $closeQuotePos = strpos($inner, '"');

            if (false !== $closeQuotePos) {
                return null;
            }

            $query = $inner;
        } else {
            // Unquoted token: whitespace inside the token means the
            // mention has ended — e.g. "Hello @Version asd" should
            // not keep showing the completion for @Version.
            if ('' !== $afterAt && preg_match('/\s/', $afterAt)) {
                return null;
            }

            $query = $afterAt;
        }

        return new AtTokenContext(
            query: $query,
            replacementStart: $lastAt,
            replacementLength: \strlen($text) - $lastAt,
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
     * Whether a path needs quoting because it contains characters
     * unsafe for unquoted shell/editor completion insertion.
     *
     * A whitelist of safe characters is used — anything outside
     * [A-Za-z0-9._@%+=:,/\\-] triggers quoting.  This covers
     * whitespace, parentheses, ampersand, semicolon, pipe, angle
     * brackets, dollar, bang, backtick, backslash, single/double
     * quotes, hash, and other interpretation-sensitive chars.
     */
    private function needsPathQuoting(string $path): bool
    {
        return 1 === preg_match('#[^'.self::SAFE_PATH_CHARS.']#', $path);
    }
}
