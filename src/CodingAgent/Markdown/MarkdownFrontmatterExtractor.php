<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Markdown;

/**
 * Extracts YAML frontmatter from Markdown content.
 *
 * Shared utility used by agent definition parsing, prompt template
 * frontmatter extraction, and skill discovery.  Callers decide whether
 * missing or malformed frontmatter is fatal or degradable.
 *
 * Behaviour:
 *  - Normalizes CRLF and CR to LF before scanning.
 *  - Strips UTF-8 BOM (U+FEFF) if present.
 *  - Frontmatter starts with "---" at offset 0 followed by a newline (the
 *    opening delimiter line must be a real delimiter line, not a prefix of
 *    a longer token such as "---title").
 *  - The closing delimiter is "\n---" or "\n..." at the start of a line.
 *    The delimiter must be on its own line: after the three chars the next
 *    character must be newline, space, tab, or EOF.  A token such as
 *    "---body" is not treated as a closing delimiter.
 *  - The YAML block is everything between the delimiters (trimmed).
 *  - The body is everything after the closing delimiter line (trimmed).
 *
 * This class has zero framework dependencies (no Symfony, no DI).
 *
 * @internal
 */
final class MarkdownFrontmatterExtractor
{
    private const OPENING_DELIMITER = '---';
    private const CLOSING_DELIMITERS = ['---', '...'];

    /**
     * Extract frontmatter and body from raw file content.
     *
     * @param string $raw Raw file bytes (may contain CRLF or CR, may carry a BOM)
     *
     * @return array{yamlBlock: string|null, body: string, hasOpeningDelimiter: bool, hasClosingDelimiter: bool}
     *                                                                                                           - yamlBlock: the raw, trimmed YAML string between delimiters (null when no opening delimiter or no closing delimiter)
     *                                                                                                           - body: the full content without the frontmatter block (when no frontmatter found, body is the entire normalized content)
     *                                                                                                           - hasOpeningDelimiter: true when content starts with a valid "---" opening line
     *                                                                                                           - hasClosingDelimiter: true when a valid closing "---" or "..." line was found
     */
    public function extract(string $raw): array
    {
        // Normalize line endings: CRLF and CR to LF.
        $content = str_replace(["\r\n", "\r"], "\n", $raw);

        // Strip UTF-8 BOM (U+FEFF) if present.
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        // Frontmatter only if content starts with a valid opening delimiter line:
        // "---" followed by EOF, newline, or only whitespace until newline/EOF.
        if (!str_starts_with($content, self::OPENING_DELIMITER)) {
            return [
                'yamlBlock' => null,
                'body' => $content,
                'hasOpeningDelimiter' => false,
                'hasClosingDelimiter' => false,
            ];
        }

        $afterOpening = substr($content, 3);

        // The rest of the opening delimiter line must be empty or whitespace-only
        // (reject "---title" and "--- extra junk").
        $openingLineEnd = strpos($afterOpening, "\n");
        $restOfOpeningLine = false !== $openingLineEnd
            ? substr($afterOpening, 0, $openingLineEnd)
            : $afterOpening;

        if ('' !== trim($restOfOpeningLine)) {
            return [
                'yamlBlock' => null,
                'body' => $content,
                'hasOpeningDelimiter' => false,
                'hasClosingDelimiter' => false,
            ];
        }

        // Search for closing delimiter after offset 3.
        $closePos = $this->findClosingDelimiter($content, 3);

        if (null === $closePos) {
            return [
                'yamlBlock' => null,
                'body' => $content,
                'hasOpeningDelimiter' => true,
                'hasClosingDelimiter' => false,
            ];
        }

        $yamlBlock = trim(substr($content, 3, $closePos - 3));

        // The body starts after the closing delimiter line.
        $closingLineEnd = strpos($content, "\n", $closePos + 1);
        $body = false !== $closingLineEnd
            ? trim(substr($content, $closingLineEnd + 1))
            : '';

        return [
            'yamlBlock' => $yamlBlock,
            'body' => $body,
            'hasOpeningDelimiter' => true,
            'hasClosingDelimiter' => true,
        ];
    }

    /**
     * Find the closing frontmatter delimiter line.
     *
     * Searches for "\n---" or "\n..." at the start of a line.
     * The delimiter must appear as its own real delimiter line: after the
     * three chars the rest of the line must be empty or whitespace-only
     * (reject "---body" and "--- extra junk").
     *
     * Returns the position of the "\n" character that precedes the closing
     * delimiter, or null if not found.
     */
    private function findClosingDelimiter(string $content, int $offset): ?int
    {
        $pos = $offset;

        while (false !== $newline = strpos($content, "\n", $pos)) {
            $afterNewline = substr($content, $newline + 1, 3);

            if (\in_array($afterNewline, self::CLOSING_DELIMITERS, true)) {
                // Verify the rest of the delimiter line is whitespace-only.
                $lineEnd = strpos($content, "\n", $newline + 1);
                $restOfLine = false !== $lineEnd
                    ? substr($content, $newline + 4, $lineEnd - $newline - 4)
                    : substr($content, $newline + 4);

                if ('' === trim($restOfLine)) {
                    return $newline;
                }
            }

            $pos = $newline + 1;
        }

        return null;
    }
}
