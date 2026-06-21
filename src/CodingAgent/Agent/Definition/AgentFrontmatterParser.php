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
 * Uses the shared {@see MarkdownFrontmatterExtractor} for delimiter scanning
 * and adds agent-definition-specific YAML-parsing and strict error handling.
 *
 * @internal
 */
final class AgentFrontmatterParser
{
    public function __construct(
        private readonly \Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor $extractor,
    ) {
    }

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
        $extraction = $this->extractor->extract($raw);

        if (!$extraction['hasOpeningDelimiter']) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): file does not start with YAML frontmatter delimiter "---".', $filePath));
        }

        if (!$extraction['hasClosingDelimiter']) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): frontmatter starts with "---" but no closing delimiter ("---" or "...") was found.', $filePath));
        }

        $frontmatter = [];
        $yamlBlock = $extraction['yamlBlock'];

        if (null !== $yamlBlock && '' !== $yamlBlock) {
            try {
                $parsed = Yaml::parse($yamlBlock);
                if (\is_array($parsed)) {
                    $frontmatter = $parsed;
                }
            } catch (\Throwable $e) {
                throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): YAML frontmatter could not be parsed: %s', $filePath, $e->getMessage()), previous: $e);
            }
        }

        return [
            'body' => $extraction['body'],
            'frontmatter' => $frontmatter,
        ];
    }
}
