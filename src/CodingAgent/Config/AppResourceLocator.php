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
     * Absolute path to the built-in agents definitions directory.
     */
    public function getBuiltinAgentsPath(): string
    {
        return $this->appRoot.'/config/agents';
    }

    /**
     * The application installation root directory.
     */
    public function getAppRoot(): string
    {
        return $this->appRoot;
    }
}
