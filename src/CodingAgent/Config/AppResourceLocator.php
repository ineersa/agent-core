<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\CodingAgent\Docs\BuiltinDocsCatalog;

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
     * Absolute path to the built-in themes directory.
     */
    public function getBuiltinThemesPath(): string
    {
        return $this->appRoot.'/config/themes';
    }

    /**
     * Absolute path to the top-level core documentation directory.
     *
     * Model-visible documents are selected via {@code builtin: true}
     * frontmatter; see {@see BuiltinDocsCatalog}.
     */
    public function getCoreDocsPath(): string
    {
        return $this->appRoot.'/'.BuiltinDocsCatalog::CORE_DOCS_RELATIVE;
    }

    /**
     * Absolute path to the public Extension API documentation directory.
     */
    public function getExtensionApiDocsPath(): string
    {
        return $this->appRoot.'/'.BuiltinDocsCatalog::EXTENSION_API_DOCS_RELATIVE;
    }

    /**
     * Absolute approved documentation roots used by {@see hatfield_docs}
     * and packaging selection.
     *
     * @return list<string>
     */
    public function getBuiltinDocsRoots(): array
    {
        return (new BuiltinDocsCatalog())->absoluteRoots($this->appRoot);
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
