<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\SystemPrompt;

/**
 * Renders discovered AGENTS.md files into XML-ish project context blocks.
 *
 * The rendered output is injected as a single user-context message between
 * the system prompt and the first user message on new sessions.
 *
 * Output format (one <project_instructions> block per file):
 *
 * <project_context>
 * Project-specific instructions and guidelines:
 *
 * <project_instructions path="/absolute/path/AGENTS.md">
 * ...file content here...
 * </project_instructions>
 * </project_context>
 *
 * When no files are discovered, returns empty string.
 */
final readonly class AgentsContextRenderer
{
    /**
     * Render discovered AGENTS.md files into XML-ish context blocks.
     *
     * @param list<array{path: string, content: string}> $discovered
     *
     * @return string Rendered XML (empty string when no files)
     */
    public function render(array $discovered): string
    {
        if ([] === $discovered) {
            return '';
        }

        $blocks = [];

        foreach ($discovered as $item) {
            $escapedPath = $this->escapeXml($item['path']);
            $escapedContent = $this->escapeXml($item['content']);

            $blocks[] = \sprintf(
                "<project_instructions path=\"%s\">\n%s\n</project_instructions>",
                $escapedPath,
                $escapedContent,
            );
        }

        return "<project_context>\nProject-specific instructions and guidelines:\n\n"
            .implode("\n", $blocks)
            ."\n</project_context>";
    }

    /**
     * XML-escape a string value using PHP's standard htmlspecialchars.
     */
    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
    }
}
