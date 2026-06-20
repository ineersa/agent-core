<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Symfony\AI\Platform\Message\Template;
use Symfony\AI\Platform\Message\TemplateRenderer\StringTemplateRenderer;

/**
 * Loads and renders the compaction summarization prompt from template files.
 *
 * Template resolution precedence (first match wins):
 *   1. {cwd}/.hatfield/COMPACTION.md  (project override)
 *   2. ~/.hatfield/COMPACTION.md      (home override)
 *   3. {projectDir}/config/COMPACTION.md (built-in default)
 *
 * Uses Symfony AI's StringTemplateRenderer for deterministic {variable}
 * placeholder substitution, matching the SystemPromptBuilder approach.
 *
 * Supported placeholders:
 *   {custom_instructions_part} — optional custom instructions block
 *   {date} — current date (Y-m-d)
 *   {cwd} — current working directory
 *
 * This service is a pure text-rendering helper; it does NOT call any LLM.
 * SessionCompactor consumes the rendered text, keeping prompt construction
 * testable without a real model.
 */
final readonly class CompactionPromptBuilder
{
    private string $projectDir;

    public function __construct(
        private SettingsPathResolver $pathResolver,
        private StringTemplateRenderer $templateRenderer,
        private AppConfig $appConfig,
        string $projectDir,
    ) {
        $this->projectDir = rtrim($projectDir, '/');
    }

    /**
     * Build and render the compaction summarization prompt.
     *
     * CWD is sourced from AppConfig. Falls back to projectDir when empty.
     *
     * @param string|null $customInstructions Optional user-provided instructions
     *
     * @return string Fully rendered compaction summarization prompt
     *
     * @throws \RuntimeException When the built-in template is missing or CWD is not configured
     */
    public function build(?string $customInstructions = null): string
    {
        if ('' === $this->appConfig->cwd) {
            throw new \RuntimeException('CWD is not configured. Ensure AppConfig::$cwd is set.');
        }

        $cwd = rtrim($this->appConfig->cwd, '/');

        $templateContent = $this->loadTemplate($cwd);

        $variables = $this->buildVariables($cwd, $customInstructions);

        return $this->render($templateContent, $variables);
    }

    /**
     * Load the template content based on precedence.
     *
     * 1. {cwd}/.hatfield/COMPACTION.md
     * 2. ~/.hatfield/COMPACTION.md
     * 3. {projectDir}/config/COMPACTION.md (built-in)
     */
    private function loadTemplate(string $cwd): string
    {
        // Project override: {cwd}/.hatfield/COMPACTION.md
        $projectPath = $cwd.'/.hatfield/COMPACTION.md';
        if (is_file($projectPath)) {
            $content = file_get_contents($projectPath);
            if (false === $content) {
                throw new \RuntimeException(\sprintf('Failed to read project COMPACTION.md: %s', $projectPath));
            }

            return $content;
        }

        // Home override: ~/.hatfield/COMPACTION.md
        $homeDir = $this->pathResolver->getHomeDir();
        $homePath = $homeDir.'/.hatfield/COMPACTION.md';
        if (is_file($homePath)) {
            $content = file_get_contents($homePath);
            if (false === $content) {
                throw new \RuntimeException(\sprintf('Failed to read home COMPACTION.md: %s', $homePath));
            }

            return $content;
        }

        // Built-in default: {projectDir}/config/COMPACTION.md
        $defaultPath = $this->projectDir.'/config/COMPACTION.md';
        if (!is_file($defaultPath)) {
            throw new \RuntimeException(\sprintf('Built-in COMPACTION.md not found at "%s". Reinstall the application or check your config/ directory.', $defaultPath));
        }

        $content = file_get_contents($defaultPath);
        if (false === $content) {
            throw new \RuntimeException(\sprintf('Failed to read built-in COMPACTION.md: %s', $defaultPath));
        }

        return $content;
    }

    /**
     * Build the template variable map.
     *
     * @param string      $cwd                Current working directory
     * @param string|null $customInstructions Optional user instructions
     *
     * @return array<string, string>
     */
    private function buildVariables(string $cwd, ?string $customInstructions): array
    {
        $customInstructionsPart = '';
        if (null !== $customInstructions && '' !== trim($customInstructions)) {
            $customInstructionsPart = "\n\nAdditional user instructions for this compaction:\n".trim($customInstructions);
        }

        return [
            'custom_instructions_part' => $customInstructionsPart,
            'date' => date('Y-m-d'),
            'cwd' => $cwd,
        ];
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
