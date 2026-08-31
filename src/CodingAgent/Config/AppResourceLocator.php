<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Locates bundled application resources (defaults, built-in themes, etc.).
 *
 * This is a separate concept from the *project working directory* (cwd).
 * The app resource root is the application installation/PHAR extraction
 * root — typically {@see %kernel.project_dir%} in non-PHAR deployments
 * or the extracted PHAR directory in PHAR deployments.
 *
 * Project-local files (e.g. {@see .hatfield/settings.yaml}) are always
 * resolved from the active project cwd supplied at resolution time, not
 * from the app resource root.
 *
 * Built-in documentation roots and selection live in {@see \Ineersa\CodingAgent\Docs\BuiltinDocsCatalog}
 * (use {@see getAppRoot()} + catalog constants/discovery; no docs path helpers here).
 */
final readonly class AppResourceLocator
{
    public function __construct(
        private string $appRoot,
    ) {
    }

    /**
     * Absolute path to the built-in Hatfield defaults YAML file.
     */
    public function getDefaultsPath(): string
    {
        return $this->appRoot.'/config/hatfield.defaults.yaml';
    }

    /**
     * Absolute path to the curated AI provider catalog YAML.
     */
    public function getAiCatalogPath(): string
    {
        return $this->appRoot.'/config/ai-catalog.yaml';
    }

    /**
     * Absolute path to the built-in themes directory.
     */
    public function getBuiltinThemesPath(): string
    {
        return $this->appRoot.'/config/themes';
    }

    /**
     * Absolute path to bundled built-in skill directories.
     *
     * Each direct child directory that contains SKILL.md is a built-in skill
     * root (for example src/CodingAgent/Resources/skills/subagents).
     */
    public function getBuiltinSkillsPath(): string
    {
        return $this->appRoot.'/src/CodingAgent/Resources/skills';
    }

    /**
     * Absolute path to bundled built-in agent definition Markdown files.
     *
     * Direct *.md files under this directory are installed into
     * ~/.hatfield/agents by agents:init (for example
     * src/CodingAgent/Resources/agents/scout.md).
     */
    public function getBuiltinAgentsPath(): string
    {
        return $this->appRoot.'/src/CodingAgent/Resources/agents';
    }

    /**
     * The application installation root directory.
     */
    public function getAppRoot(): string
    {
        return $this->appRoot;
    }
}
