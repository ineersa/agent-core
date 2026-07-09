<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\SystemPrompt;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use Ineersa\Hatfield\ExtensionApi\Prompt\PromptContributorProviderInterface;
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
        private AppConfig $appConfig,
        string $projectDir,
        private ?PromptContributorProviderInterface $promptContributorProvider = null,
    ) {
        $this->projectDir = rtrim($projectDir, '/');
    }

    /**
     * Build and render the system prompt.
     *
     * CWD is sourced from AppConfig (bootstrap-resolved working directory).
     * Falls back to projectDir when AppConfig.cwd is empty.
     *
     * @return string Fully rendered system prompt
     *
     * @throws \RuntimeException When the built-in template is missing or CWD is not configured
     */
    public function build(): string
    {
        if ('' === $this->appConfig->cwd) {
            throw new \RuntimeException('CWD is not configured. Ensure AppConfig::$cwd is set.');
        }

        $cwd = rtrim($this->appConfig->cwd, '/');

        // Resolve and render the base template.
        $baseContent = $this->loadBaseTemplate($cwd);

        // Resolve and render append templates (with empty appends_part to avoid recursion).
        $appendsContent = $this->buildAppendsContent($cwd);

        // Build the variable map shared by both base and append rendering.
        $variables = $this->buildVariables($cwd, $appendsContent);

        return $this->render($baseContent, $variables);
    }

    /**
     * Build the main SYSTEM.md harness rendered for a restricted tool allow-list.
     *
     * Used by fork children (main-agent-like context) instead of SUBAGENT_SYSTEM.md.
     *
     * @param list<string> $allowedToolNames runtime tool names for the child
     */
    public function buildMainHarnessForAllowedTools(array $allowedToolNames): string
    {
        if ('' === $this->appConfig->cwd) {
            throw new \RuntimeException('CWD is not configured. Ensure AppConfig::$cwd is set.');
        }

        $cwd = rtrim($this->appConfig->cwd, '/');
        $baseContent = $this->loadBaseTemplate($cwd);
        $variables = $this->buildChildVariables($cwd, $allowedToolNames, '');

        return $this->render($baseContent, $variables);
    }

    /**
     * Build the SUBAGENT_SYSTEM.md harness fragment for definition-backed subagent children.
     *
     * @param list<string> $allowedToolNames runtime tool names for the child
     */
    public function buildChildHarnessFragment(array $allowedToolNames): string
    {
        if ('' === $this->appConfig->cwd) {
            throw new \RuntimeException('CWD is not configured. Ensure AppConfig::$cwd is set.');
        }

        $cwd = rtrim($this->appConfig->cwd, '/');
        $templatePath = $this->projectDir.'/config/SUBAGENT_SYSTEM.md';
        if (!is_file($templatePath)) {
            throw new \RuntimeException(\sprintf('Built-in SUBAGENT_SYSTEM.md not found at "%s".', $templatePath));
        }

        $content = file_get_contents($templatePath);
        if (false === $content) {
            throw new \RuntimeException(\sprintf('Failed to read SUBAGENT_SYSTEM.md: %s', $templatePath));
        }

        $variables = $this->buildChildVariables($cwd, $allowedToolNames, '');

        return $this->render($content, $variables);
    }

    /**
     * Build rendered APPEND_SYSTEM.md (+ prompt contributors) for child append mode.
     *
     * Uses child-safe placeholders ({available_tools_list}, {registered_guidelines},
     * date, cwd) and empty {appends_part} to avoid recursion.
     *
     * @param list<string> $allowedToolNames runtime tool names for the child
     */
    public function buildChildAppendsFragment(array $allowedToolNames): string
    {
        if ('' === $this->appConfig->cwd) {
            throw new \RuntimeException('CWD is not configured. Ensure AppConfig::$cwd is set.');
        }

        $cwd = rtrim($this->appConfig->cwd, '/');

        return $this->buildChildAppendsContent($cwd, $allowedToolNames);
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

        // Drain prompt contributors (extension-registered) BEFORE the
        // empty-parts check, so contributors still apply when no static
        // APPEND_SYSTEM.md files exist (the common case).
        $contributorOutput = $this->drainContributors();
        if ('' !== $contributorOutput) {
            $parts[] = $contributorOutput;
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
     * Drain registered prompt contributors and concatenate their output.
     *
     * Empty contributions are skipped. Contributors run in registration order.
     */
    private function drainContributors(): string
    {
        if (null === $this->promptContributorProvider) {
            return '';
        }

        $contributions = [];
        foreach ($this->promptContributorProvider->promptContributors() as $contributor) {
            $output = $contributor->contribute();
            if ('' !== $output) {
                $contributions[] = $output;
            }
        }

        return implode("\n\n", $contributions);
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
     * @param list<string> $allowedToolNames
     *
     * @return array<string, string>
     */
    private function buildChildVariables(string $cwd, array $allowedToolNames, string $appendsContent): array
    {
        return [
            'available_tools_list' => $this->buildToolsListForNames($allowedToolNames),
            'registered_guidelines' => $this->buildGuidelinesForNames($allowedToolNames),
            'appends_part' => $appendsContent,
            'date' => date('Y-m-d'),
            'cwd' => $cwd,
        ];
    }

    /**
     * @param list<string> $allowedToolNames
     */
    private function buildChildAppendsContent(string $cwd, array $allowedToolNames): string
    {
        $parts = [];

        $homeDir = $this->pathResolver->getHomeDir();
        $homeAppend = $homeDir.'/.hatfield/APPEND_SYSTEM.md';
        if (is_file($homeAppend)) {
            $content = file_get_contents($homeAppend);
            if (false !== $content && '' !== $content) {
                $parts[] = $content;
            }
        }

        $projectAppend = $cwd.'/.hatfield/APPEND_SYSTEM.md';
        if (is_file($projectAppend)) {
            $content = file_get_contents($projectAppend);
            if (false !== $content && '' !== $content) {
                $parts[] = $content;
            }
        }

        $contributorOutput = $this->drainContributors();
        if ('' !== $contributorOutput) {
            $contributorOutput = $this->filterContributorOutputForChildAllowedTools($contributorOutput, $allowedToolNames);
            if ('' !== trim($contributorOutput)) {
                $parts[] = $contributorOutput;
            }
        }

        if ([] === $parts) {
            return '';
        }

        $concatenated = implode("\n\n", $parts);
        $appendVariables = $this->buildChildVariables($cwd, $allowedToolNames, '');

        $rendered = $this->render($concatenated, $appendVariables);

        return $this->sanitizeChildAppendContent($rendered, $allowedToolNames);
    }

    /**
     * @param list<string> $allowedToolNames
     */
    private function buildToolsListForNames(array $allowedToolNames): string
    {
        $lines = [];
        $seen = [];

        foreach ($allowedToolNames as $name) {
            $name = trim($name);
            if ('' === $name) {
                continue;
            }

            $definition = $this->toolRegistry->toolDefinition($name);
            if (null === $definition) {
                continue;
            }

            $line = $definition->promptLine;
            if ('' === $line) {
                $line = '- '.$name.': '.$definition->description;
            }

            if (!isset($seen[$line])) {
                $seen[$line] = true;
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $allowedToolNames
     */
    private function buildGuidelinesForNames(array $allowedToolNames): string
    {
        $guidelines = [];
        $seen = [];

        foreach ($allowedToolNames as $name) {
            $name = trim($name);
            if ('' === $name) {
                continue;
            }

            $definition = $this->toolRegistry->toolDefinition($name);
            if (null === $definition) {
                continue;
            }

            foreach ($definition->promptGuidelines as $guideline) {
                if ('' === $guideline) {
                    continue;
                }
                if (!isset($seen[$guideline])) {
                    $seen[$guideline] = true;
                    $guidelines[] = $guideline;
                }
            }
        }

        return implode("\n", $guidelines);
    }

    /**
     * Filter extension prompt contributor output to the child allowed toolset.
     *
     * Contributors may dump parent-scope tool catalogs; child appends must only
     * document tools the child can actually invoke.
     *
     * @param list<string> $allowedToolNames
     */
    private function filterContributorOutputForChildAllowedTools(string $content, array $allowedToolNames): string
    {
        return $this->sanitizeChildAppendContent($content, $allowedToolNames);
    }

    /**
     * Strip disallowed-tool prompt/guideline lines and fork-parent tool docs from
     * child append content so fork/subagent children never see stale tool catalogs.
     *
     * @param list<string> $allowedToolNames
     */
    private function sanitizeChildAppendContent(string $content, array $allowedToolNames): string
    {
        if ('' === trim($content)) {
            return '';
        }

        $allowed = [];
        foreach ($allowedToolNames as $name) {
            $name = trim($name);
            if ('' !== $name) {
                $allowed[$name] = true;
            }
        }

        $disallowedLines = [];
        $disallowedGuidelines = [];
        foreach ($this->toolRegistry->activeToolNames() as $name) {
            if (isset($allowed[$name])) {
                continue;
            }

            $definition = $this->toolRegistry->toolDefinition($name);
            if (null === $definition) {
                continue;
            }

            if ('' !== $definition->promptLine) {
                $disallowedLines[$definition->promptLine] = true;
            }

            foreach ($definition->promptGuidelines as $guideline) {
                if ('' !== trim($guideline)) {
                    $disallowedGuidelines[trim($guideline)] = true;
                }
            }
        }

        $split = preg_split("/\r\n|\n|\r/", $content);
        $lines = false === $split ? [] : $split;
        $kept = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ('' === $trimmed) {
                $kept[] = $line;
                continue;
            }

            if (isset($disallowedLines[$trimmed]) || isset($disallowedGuidelines[$trimmed])) {
                continue;
            }

            if (preg_match('/^-\s*([A-Za-z0-9_]+):/u', $trimmed, $matches)) {
                $toolName = strtolower($matches[1]);
                if (!isset($allowed[$toolName])) {
                    continue;
                }
            }

            $lower = strtolower($trimmed);
            if (str_contains($lower, 'fork task=')
                || str_contains($lower, 'use fork')
                || str_contains($lower, 'launch fork child')) {
                continue;
            }

            $kept[] = $line;
        }

        return rtrim(implode("\n", $kept));
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
