<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\PromptTemplate;

use Ineersa\CodingAgent\Config\PromptsConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Psr\Log\LoggerInterface;

/**
 * Loads prompt templates from all configured sources in deterministic order.
 *
 * Loading order (mirrors Pi core loader adapted for Hatfield settings):
 *  1. Global auto-discovery: ~/.hatfield/prompts/*.md  (unless disabled)
 *  2. Project auto-discovery: <cwd>/.hatfield/prompts/*.md  (unless disabled)
 *  3. Settings paths: top-level prompts: []  (unless disabled)
 *  4. CLI paths: --prompt-template <path>  (always, even when disabled)
 *
 * Directory scans are non-recursive. Only exact .md suffix files are loaded.
 * Auto-discovery directories that don't exist are silently skipped.
 * Explicit settings/CLI paths that don't exist produce an invalid_path diagnostic.
 *
 * Template names are canonicalized to lowercase using the filename stem:
 *   strtolower(basename($path, '.md'))
 * First lowercase name wins; later duplicates produce a collision diagnostic.
 *
 * Description:
 *  - Frontmatter 'description' if present and non-empty.
 *  - Otherwise, first non-empty body line truncated to 60 chars with "...".
 *
 * Never logs raw template content.
 *
 * @internal
 */
final class PromptTemplateLoader
{
    public function __construct(
        private readonly PromptsConfig $promptsConfig,
        private readonly PromptTemplatesRuntimeConfig $runtimeConfig,
        private readonly SettingsPathResolver $pathResolver,
        private readonly string $cwd,
        private readonly PromptTemplateFrontmatterParser $frontmatterParser,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function load(): PromptTemplateLoadResult
    {
        /** @var array<string, LoadedPromptTemplate> $loadedByName */
        $loadedByName = [];
        $diagnostics = [];

        if (!$this->runtimeConfig->noPromptTemplates) {
            // 1. Global auto-discovery
            $this->loadDirectory(
                rtrim($this->pathResolver->getHomeDir(), '/').'/.hatfield/prompts',
                $loadedByName,
                $diagnostics,
                true, // isAutoDiscovery
            );

            // 2. Project auto-discovery
            $this->loadDirectory(
                rtrim($this->cwd, '/').'/.hatfield/prompts',
                $loadedByName,
                $diagnostics,
                true, // isAutoDiscovery
            );

            // 3. Settings paths
            foreach ($this->promptsConfig->paths as $path) {
                $this->loadPath($path, $loadedByName, $diagnostics);
            }
        }

        // 4. CLI paths — always loaded even when noPromptTemplates is true.
        foreach ($this->runtimeConfig->promptTemplatePaths as $path) {
            $this->loadPath($path, $loadedByName, $diagnostics);
        }

        return new PromptTemplateLoadResult(array_values($loadedByName), $diagnostics);
    }

    /**
     * Load all .md files from a directory non-recursively.
     *
     * Missing auto-discovery dirs are silent. Missing explicit dirs produce
     * a diagnostic (handled by loadPath, not here).
     *
     * @param array<string, LoadedPromptTemplate> $loadedByName
     * @param list<PromptTemplateDiagnostic>      $diagnostics
     */
    private function loadDirectory(
        string $dir,
        array &$loadedByName,
        array &$diagnostics,
        bool $isAutoDiscovery = false,
    ): void {
        if (!is_dir($dir)) {
            if (!$isAutoDiscovery) {
                $diagnostics[] = new PromptTemplateDiagnostic(
                    type: 'invalid_path',
                    message: \sprintf('Prompt template directory not found: %s', $dir),
                    path: $dir,
                );
            }

            return;
        }

        $entries = scandir($dir);
        if (false === $entries) {
            return;
        }

        // Sort lexically for deterministic tests.
        sort($entries);

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $filePath = rtrim($dir, '/').'/'.$entry;

            if (!is_file($filePath)) {
                continue;
            }

            // Only exact .md suffix, case-sensitive.
            if (!str_ends_with($entry, '.md')) {
                continue;
            }

            // Skip non-Markdown files that happen to end in .md like hidden files.
            // Pi loads any .md file regardless of leading dot.
            $this->loadFile($filePath, $loadedByName, $diagnostics);
        }
    }

    /**
     * Load a single explicit path (file or directory).
     *
     * @param array<string, LoadedPromptTemplate> $loadedByName
     * @param list<PromptTemplateDiagnostic>      $diagnostics
     */
    private function loadPath(
        string $path,
        array &$loadedByName,
        array &$diagnostics,
    ): void {
        if (!file_exists($path)) {
            $diagnostics[] = new PromptTemplateDiagnostic(
                type: 'invalid_path',
                message: \sprintf('Prompt template path not found: %s', $path),
                path: $path,
            );

            return;
        }

        if (is_dir($path)) {
            $this->loadDirectory($path, $loadedByName, $diagnostics, false);
        } elseif (is_file($path)) {
            if (str_ends_with(basename($path), '.md')) {
                $this->loadFile($path, $loadedByName, $diagnostics);
            }
        }
    }

    /**
     * Load a single .md template file into the loaded map.
     *
     * @param array<string, LoadedPromptTemplate> $loadedByName
     * @param list<PromptTemplateDiagnostic>      $diagnostics
     */
    private function loadFile(
        string $filePath,
        array &$loadedByName,
        array &$diagnostics,
    ): void {
        $name = strtolower(basename($filePath, '.md'));

        // Collision: first-loaded wins.
        if (isset($loadedByName[$name])) {
            $diagnostics[] = new PromptTemplateDiagnostic(
                type: 'collision',
                message: \sprintf(
                    'Prompt template name collision: "%s" from "%s" was already loaded; ignoring "%s"',
                    $name,
                    $loadedByName[$name]->filePath,
                    $filePath,
                ),
                path: $filePath,
                name: $name,
                winnerPath: $loadedByName[$name]->filePath,
                loserPath: $filePath,
            );

            $this->logger->debug('prompt_template.collision', [
                'component' => 'prompt_template_loader',
                'event_type' => 'prompt_template.collision',
                'name' => $name,
                'winner_path' => $loadedByName[$name]->filePath,
                'loser_path' => $filePath,
            ]);

            return;
        }

        // Read file.
        $raw = @file_get_contents($filePath);
        if (false === $raw) {
            $diagnostics[] = new PromptTemplateDiagnostic(
                type: 'read_error',
                message: \sprintf('Could not read prompt template: %s', $filePath),
                path: $filePath,
            );

            $this->logger->warning('prompt_template.read_error', [
                'component' => 'prompt_template_loader',
                'event_type' => 'prompt_template.read_error',
                'path' => $filePath,
            ]);

            return;
        }

        // Parse frontmatter.
        $parsed = $this->frontmatterParser->parse($raw, $filePath);

        // Collect any frontmatter parsing diagnostics.
        foreach ($parsed['diagnostics'] as $diag) {
            $diagnostics[] = $diag;

            $this->logger->warning('prompt_template.frontmatter_parse_failed', [
                'component' => 'prompt_template_loader',
                'event_type' => 'prompt_template.frontmatter_parse_failed',
                'path' => $filePath,
            ]);
        }

        $body = $parsed['body'];
        $description = $parsed['description'];

        // Description fallback: first non-empty body line, truncated to 60 chars.
        if ('' === $description) {
            $description = $this->deriveDescriptionFromBody($body);
        }

        $loadedByName[$name] = new LoadedPromptTemplate(
            name: $name,
            description: $description,
            content: $body,
            filePath: $filePath,
        );
    }

    /**
     * Derive a description from the first non-empty line of the template body.
     *
     * Lines longer than 60 characters are truncated with "...".
     */
    private function deriveDescriptionFromBody(string $body): string
    {
        $lines = explode("\n", $body);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ('' !== $trimmed) {
                return mb_strlen($trimmed) > 60
                    ? mb_substr($trimmed, 0, 60).'...'
                    : $trimmed;
            }
        }

        return '';
    }
}
