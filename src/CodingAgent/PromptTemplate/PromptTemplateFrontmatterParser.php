<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\PromptTemplate;

use Symfony\Component\Yaml\Yaml;

/**
 * Parses YAML frontmatter from Markdown prompt-template files.
 *
 * Behaviour:
 *  - Normalizes CRLF and CR to LF before parsing.
 *  - Frontmatter exists only if content starts with "---" and a closing
 *    "\n---" delimiter is found after offset 3.
 *  - The YAML block is everything between the delimiters.
 *  - The body is the trimmed text after the closing delimiter.
 *  - If no valid frontmatter, metadata is empty and body is the normalized
 *    original content.
 *  - Unknown frontmatter keys (including Pi's argument-hint) are ignored;
 *    only 'description' (string) is read.
 *  - Invalid YAML produces a diagnostic; the body is still returned with
 *    empty metadata so the template remains usable (local degradation).
 *
 * @internal
 */
final class PromptTemplateFrontmatterParser
{
    /**
     * Parse frontmatter and body from raw file content.
     *
     * @param string $raw      raw file bytes (may contain CRLF or CR)
     * @param string $filePath absolute file path for diagnostics context
     *
     * @return array{body: string, description: string, diagnostics: list<PromptTemplateDiagnostic>}
     */
    public function parse(string $raw, string $filePath): array
    {
        // Normalize line endings: CRLF and CR to LF.
        $content = str_replace(["\r\n", "\r"], "\n", $raw);

        $delimiter = '---';

        // Frontmatter only if the content starts with the delimiter.
        if (!str_starts_with($content, $delimiter)) {
            return [
                'body' => $content,
                'description' => '',
                'diagnostics' => [],
            ];
        }

        // Search for closing delimiter after offset 3.
        $closePos = strpos($content, "\n".$delimiter, 3);

        if (false === $closePos) {
            // Starts with --- but no closing delimiter: treat as body, no frontmatter.
            return [
                'body' => $content,
                'description' => '',
                'diagnostics' => [],
            ];
        }

        $yamlString = substr($content, 3, $closePos - 3);
        $body = substr($content, $closePos + 4); // skip "\n---"

        // Normalize: trim whitespace from the body.
        $body = trim($body);

        $description = '';
        $diagnostics = [];

        // Parse YAML frontmatter.
        $yamlString = trim($yamlString);
        if ('' !== $yamlString) {
            try {
                $parsed = Yaml::parse($yamlString);
                if (\is_array($parsed)) {
                    // Only extract description; ignore unknown keys.
                    if (isset($parsed['description']) && \is_string($parsed['description']) && '' !== trim($parsed['description'])) {
                        $description = trim($parsed['description']);
                    }
                }
            } catch (\Throwable $e) {
                // Invalid YAML → local degradation: return body with empty metadata
                // and a diagnostic. The caller (loader) should log this.
                $diagnostics[] = new PromptTemplateDiagnostic(
                    type: 'yaml_error',
                    message: 'Prompt template frontmatter could not be parsed',
                    path: $filePath,
                );
            }
        }

        return [
            'body' => $body,
            'description' => $description,
            'diagnostics' => $diagnostics,
        ];
    }
}
