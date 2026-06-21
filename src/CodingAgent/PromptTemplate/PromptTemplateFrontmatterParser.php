<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\PromptTemplate;

use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses YAML frontmatter from Markdown prompt-template files.
 *
 * Uses the shared {@see MarkdownFrontmatterExtractor} for delimiter scanning
 * and adds prompt-template-specific graceful degradation on invalid/missing
 * frontmatter.
 *
 * Behaviour:
 *  - Normalizes CRLF and CR to LF before parsing (via shared extractor).
 *  - UTF-8 BOM is stripped (via shared extractor).
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
    public function __construct(
        private readonly MarkdownFrontmatterExtractor $extractor,
    ) {
    }

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
        $extraction = $this->extractor->extract($raw);

        // No valid frontmatter → return body as-is with empty metadata.
        if (null === $extraction['yamlBlock']) {
            return [
                'body' => $extraction['body'],
                'description' => '',
                'diagnostics' => [],
            ];
        }

        $description = '';
        $diagnostics = [];
        $yamlBlock = $extraction['yamlBlock'];

        if ('' !== $yamlBlock) {
            try {
                $parsed = Yaml::parse($yamlBlock);
                if (\is_array($parsed)) {
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
            'body' => $extraction['body'],
            'description' => $description,
            'diagnostics' => $diagnostics,
        ];
    }
}
