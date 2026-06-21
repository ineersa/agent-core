<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Definition;

use Symfony\Component\Yaml\Yaml;

/**
 * Extracts and parses YAML frontmatter from agent-definition Markdown files.
 *
 * Agent definitions REQUIRE valid frontmatter.  This is stricter than prompt
 * templates (which degrade gracefully without it).
 *
 * Behaviour:
 *  - Normalizes CRLF and CR to LF before parsing.
 *  - Frontmatter starts with "---" at offset 0 and ends with "\n---" (or
 *    "\n...") after offset 3.
 *  - If no frontmatter is found or the delimiters are unclosed, an exception
 *    is thrown.
 *  - Invalid YAML causes an exception (unlike prompt templates which degrade).
 *  - Unknown keys are kept in the parsed result so the validator can reject
 *    them later.
 *
 * @internal
 */
final class AgentFrontmatterParser
{
    /**
     * Parse frontmatter and body from raw file content.
     *
     * @param string $raw      Raw file bytes (may contain CRLF or CR)
     * @param string $filePath Absolute file path for error messages
     *
     * @return array{body: string, frontmatter: array<string, mixed>}
     *
     * @throws AgentDefinitionValidationException on missing frontmatter or invalid YAML
     */
    public function parse(string $raw, string $filePath): array
    {
        // Normalize line endings: CRLF and CR to LF.
        $content = str_replace(["\r\n", "\r"], "\n", $raw);

        // Strip UTF-8 BOM (U+FEFF) if present, before checking for opening delimiter.
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $openDelimiter = '---';

        // Frontmatter only if the content starts with the opening delimiter.
        if (!str_starts_with($content, $openDelimiter)) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): file does not start with YAML frontmatter delimiter "---".', $filePath));
        }

        // Search for closing delimiter after offset 3: "---" or "..."
        $closePos = $this->findClosingDelimiter($content, 3);

        if (null === $closePos) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): frontmatter starts with "---" but no closing delimiter ("---" or "...") was found.', $filePath));
        }

        $yamlString = substr($content, 3, $closePos - 3);
        // The body starts after the closing delimiter line.
        // $closePos points to the "\n" before the closing delimiter; skip that
        // newline, the delimiter string, and the newline after it.
        $closingLineEnd = strpos($content, "\n", $closePos + 1);
        if (false !== $closingLineEnd) {
            $body = substr($content, $closingLineEnd + 1);
        } else {
            $body = '';
        }

        $body = trim($body);

        $frontmatter = [];

        $yamlString = trim($yamlString);
        if ('' !== $yamlString) {
            try {
                $parsed = Yaml::parse($yamlString);
                if (\is_array($parsed)) {
                    $frontmatter = $parsed;
                }
            } catch (\Throwable $e) {
                throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): YAML frontmatter could not be parsed: %s', $filePath, $e->getMessage()), previous: $e);
            }
        }

        return [
            'body' => $body,
            'frontmatter' => $frontmatter,
        ];
    }

    /**
     * Find the closing frontmatter delimiter line.
     *
     * Searches for "\n---" or "\n..." at the start of a line.
     * The delimiter must appear as its own line: after the three chars,
     * the next character must be a newline or EOF (not another char that
     * would make the delimiter part of a longer word such as "---body").
     *
     * Returns the position of the "\n" character that precedes the closing
     * delimiter, or null if not found.
     */
    private function findClosingDelimiter(string $content, int $offset): ?int
    {
        $pos = $offset;

        while (false !== $newline = strpos($content, "\n", $pos)) {
            $afterNewline = substr($content, $newline + 1, 3);

            if ('---' === $afterNewline || '...' === $afterNewline) {
                // The next character after the 3-char delimiter must be
                // a newline, EOF, or whitespace (so "---body" does not
                // match as a closing delimiter).
                $nextCharPos = $newline + 4;
                if ($nextCharPos >= \strlen($content) || "\n" === $content[$nextCharPos] || ' ' === $content[$nextCharPos] || "\t" === $content[$nextCharPos]) {
                    return $newline;
                }
                // Delimiter is part of a longer token; keep searching.
            }

            $pos = $newline + 1;
        }

        return null;
    }
}
