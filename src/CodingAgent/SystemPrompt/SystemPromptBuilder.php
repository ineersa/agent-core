<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\SystemPrompt;

use Ineersa\CodingAgent\Tool\ToolRegistryInterface;

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
 * {%appends_part%} is empty to avoid infinite recursion.
 *
 * Supported placeholders:
 *   {%available_tools_list%} — deduped permanent tool prompt lines
 *   {%registered_guidelines%} — deduped permanent tool guidelines
 *   {%appends_part%} — rendered append template content
 *   {%date%} — current date (Y-m-d)
 *   {%cwd%} — current working directory
 *
 * This class lives in CodingAgent because it depends on ToolRegistryInterface
 * (CodingAgent-owned). AgentCore and TUI must not depend on it.
 */
final readonly class SystemPromptBuilder
{
    private string $projectDir;

    public function __construct(
        private ToolRegistryInterface $toolRegistry,
        string $projectDir,
    ) {
        $this->projectDir = rtrim($projectDir, '/');
    }

    /**
     * Build and render the system prompt.
     *
     * @param string $cwd Current working directory (for {%cwd%} and template lookup)
     *
     * @return string Fully rendered system prompt
     *
     * @throws \RuntimeException When the built-in template is missing
     */
    public function build(string $cwd = ''): string
    {
        $cwd = '' !== $cwd ? $cwd : $this->resolveCwd();
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
        $homeDir = $this->resolveHomeDir();
        if ('' !== $homeDir) {
            $homeSystem = $homeDir.'/.hatfield/SYSTEM.md';
            if (is_file($homeSystem)) {
                $content = file_get_contents($homeSystem);
                if (false === $content) {
                    throw new \RuntimeException(\sprintf('Failed to read home SYSTEM.md: %s', $homeSystem));
                }

                return $content;
            }
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
     * set except {%appends_part%} is empty.
     */
    private function buildAppendsContent(string $cwd): string
    {
        $parts = [];

        // Home append: ~/.hatfield/APPEND_SYSTEM.md
        $homeDir = $this->resolveHomeDir();
        if ('' !== $homeDir) {
            $homeAppend = $homeDir.'/.hatfield/APPEND_SYSTEM.md';
            if (is_file($homeAppend)) {
                $content = file_get_contents($homeAppend);
                if (false !== $content && '' !== $content) {
                    $parts[] = $content;
                }
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

        // Render append content with empty {%appends_part%} to avoid recursion.
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
     * Render a template by replacing {%key%} placeholders with variable values.
     *
     * @param array<string, string> $variables
     *
     * This is a tiny deterministic renderer limited to the supported placeholders.
     * No Twig, no Markdown, no Symfony Template dependency.
     */
    private function render(string $template, array $variables): string
    {
        $result = $template;

        foreach ($variables as $key => $value) {
            $result = str_replace('{%'.$key.'%}', $value, $result);
        }

        return $result;
    }

    /**
     * Resolve the user's home directory from OS environment.
     */
    private function resolveHomeDir(): string
    {
        // Linux/Unix
        $home = getenv('HOME');
        if (\is_string($home) && '' !== $home) {
            return $home;
        }

        // Windows
        $userProfile = getenv('USERPROFILE');
        if (\is_string($userProfile) && '' !== $userProfile) {
            return $userProfile;
        }

        // HOMEDRIVE + HOMEPATH (Windows fallback)
        $drive = getenv('HOMEDRIVE');
        $path = getenv('HOMEPATH');
        if (\is_string($drive) && \is_string($path)) {
            return $drive.$path;
        }

        return '';
    }

    /**
     * Resolve the current working directory, falling back to project dir.
     */
    private function resolveCwd(): string
    {
        $cwd = getcwd();

        return false !== $cwd ? $cwd : $this->projectDir;
    }
}
