<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\SystemPrompt;

use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use Symfony\AI\Platform\Message\Template;
use Symfony\AI\Platform\Message\TemplateRenderer\StringTemplateRenderer;

/**
 * Builds and renders the Hatfield system prompt from template files.
 *
 * Template resolution precedence (first match wins):
 *   1. {cwd}/.hatfield/SYSTEM.md  (project override)
 *   2. ~/.hatfield/SYSTEM.md      (home override)
 *   3. {projectDir}/config/SYSTEM.md (built-in default)
 *
 * Append templates are loaded from (both, when present):
 *   1. ~/.hatfield/APPEND_SYSTEM.md
 *   2. {cwd}/.hatfield/APPEND_SYSTEM.md
 *
 * Rendered with the same variables as the base template, except
 * {appends_part} is empty to avoid infinite recursion.
 *
 * Uses Symfony AI's StringTemplateRenderer for deterministic {variable}
 * placeholder substitution.
 *
 * Supported placeholders:
 *   {available_tools_list} — deduped permanent tool prompt lines
 *   {registered_guidelines} — deduped permanent tool guidelines
 *   {appends_part} — rendered append template content
 *   {date} — current date (Y-m-d)
 *   {cwd} — current working directory
 *
 * Uses SettingsPathResolver for home-directory resolution (shared with
 * other Hatfield config loading) instead of its own environment sniffing.
 *
 * This class lives in CodingAgent because it depends on ToolRegistryInterface
 * (CodingAgent-owned). AgentCore and TUI must not depend on it.
 */
final readonly class SystemPromptBuilder
{
    private string $projectDir;

    public function __construct(
        private ToolRegistryInterface $toolRegistry,
        private SettingsPathResolver $pathResolver,
        private StringTemplateRenderer $templateRenderer,
        string $projectDir,
    ) {
        $this->projectDir = rtrim($projectDir, '/');
    }

    /**
     * Build and render the system prompt.
     *
     * @param string $cwd Current working directory (for {cwd} and template lookup)
     *
     * @return string Fully rendered system prompt
     *
     * @throws \RuntimeException When the built-in template is missing
     */
    public function build(string $cwd = ''): string
    {
        $cwd = '' !== $cwd ? $cwd : $this->projectDir;
        $cwd = rtrim($cwd, '/');

        // Resolve and render the base template.
        $baseContent = $this->loadBaseTemplate($cwd);

        // Resolve and render append templates (with empty appends_part to avoid recursion).
        $appendsContent = $this->buildAppendsContent($cwd);

        // Build the variable map shared by both base and append rendering.
        $variables = $this->buildVariables($cwd, $appendsContent);

        return $this->render($baseContent, $variables);
    }

    /**
     * Load the base template content based on precedence.
     *
     * 1. {cwd}/.hatfield/SYSTEM.md
     * 2. ~/.hatfield/SYSTEM.md
     * 3. {projectDir}/config/SYSTEM.md (built-in)
     */
    private function loadBaseTemplate(string $cwd): string
    {
        // Project override: {cwd}/.hatfield/SYSTEM.md
        $projectSystem = $cwd.'/.hatfield/SYSTEM.md';
        if (is_file($projectSystem)) {
            $content = file_get_contents($projectSystem);
            if (false === $content) {
                throw new \RuntimeException(\sprintf('Failed to read project SYSTEM.md: %s', $projectSystem));
            }

            return $content;
        }

        // Home override: ~/.hatfield/SYSTEM.md
        $homeDir = $this->pathResolver->getHomeDir();
        $homeSystem = $homeDir.'/.hatfield/SYSTEM.md';
        if (is_file($homeSystem)) {
            $content = file_get_contents($homeSystem);
            if (false === $content) {
                throw new \RuntimeException(\sprintf('Failed to read home SYSTEM.md: %s', $homeSystem));
            }

            return $content;
        }

        // Built-in default: {projectDir}/config/SYSTEM.md
        $defaultPath = $this->projectDir.'/config/SYSTEM.md';
        if (!is_file($defaultPath)) {
            throw new \RuntimeException(\sprintf('Built-in SYSTEM.md not found at "%s". Reinstall the application or check your config/ directory.', $defaultPath));
        }

        $content = file_get_contents($defaultPath);
        if (false === $content) {
            throw new \RuntimeException(\sprintf('Failed to read built-in SYSTEM.md: %s', $defaultPath));
        }

        return $content;
    }

    /**
     * Build the rendered append templates content.
     *
     * Loads ~/.hatfield/APPEND_SYSTEM.md and {cwd}/.hatfield/APPEND_SYSTEM.md,
     * concatenates with blank-line separator, and renders with the same variable
     * set except {appends_part} is empty.
     */
    private function buildAppendsContent(string $cwd): string
    {
        $parts = [];

        // Home append: ~/.hatfield/APPEND_SYSTEM.md
        $homeDir = $this->pathResolver->getHomeDir();
        $homeAppend = $homeDir.'/.hatfield/APPEND_SYSTEM.md';
        if (is_file($homeAppend)) {
            $content = file_get_contents($homeAppend);
            if (false !== $content && '' !== $content) {
                $parts[] = $content;
            }
        }

        // Project append: {cwd}/.hatfield/APPEND_SYSTEM.md
        $projectAppend = $cwd.'/.hatfield/APPEND_SYSTEM.md';
        if (is_file($projectAppend)) {
            $content = file_get_contents($projectAppend);
            if (false !== $content && '' !== $content) {
                $parts[] = $content;
            }
        }

        if ([] === $parts) {
            return '';
        }

        // Concatenate with blank-line separator.
        $concatenated = implode("\n\n", $parts);

        // Render append content with empty {appends_part} to avoid recursion.
        $appendVariables = $this->buildVariables($cwd, '');

        return $this->render($concatenated, $appendVariables);
    }

    /**
     * Build the template variable map.
     *
     * @param string $cwd            Current working directory
     * @param string $appendsContent Already-rendered append content (or empty string)
     *
     * @return array<string, string>
     */
    private function buildVariables(string $cwd, string $appendsContent): array
    {
        return [
            'available_tools_list' => $this->buildToolsList(),
            'registered_guidelines' => $this->buildGuidelines(),
            'appends_part' => $appendsContent,
            'date' => date('Y-m-d'),
            'cwd' => $cwd,
        ];
    }

    /**
     * Build the deduped available-tools list from permanent tool prompt lines.
     */
    private function buildToolsList(): string
    {
        $lines = $this->toolRegistry->permanentToolLines();

        return implode("\n", $lines);
    }

    /**
     * Build the deduped guidelines string from permanent tools.
     */
    private function buildGuidelines(): string
    {
        $guidelines = $this->toolRegistry->permanentGuidelines();

        return implode("\n", $guidelines);
    }

    /**
     * Render a template using Symfony AI's StringTemplateRenderer.
     *
     * Placeholders use {variable} syntax (e.g. {date}, {cwd}).
     *
     * @param array<string, string> $variables
     */
    private function render(string $template, array $variables): string
    {
        return $this->templateRenderer->render(Template::string($template), $variables);
    }
}
