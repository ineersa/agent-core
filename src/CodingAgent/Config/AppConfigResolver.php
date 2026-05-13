<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Public API for resolving Hatfield application config.
 *
 * Inject this service wherever app config is needed.
 * It loads defaults + home + project settings with correct precedence.
 *
 * Usage:
 *   $config = $resolver->resolve($request->cwd ?: getcwd());
 *   $themeName = $config->tui->theme;
 *   $themePaths = $config->tui->themePaths;
 */
final class AppConfigResolver
{
    /**
     * Path to the built-in defaults YAML file (bundled with the app).
     */
    private string $defaultsPath;

    /** @var array<string, AppConfig> Cache keyed by resolved project cwd */
    private array $cache = [];

    public function __construct(
        private readonly AppConfigLoader $loader,
        string $projectDir,
    ) {
        // Built-in defaults live alongside the app installation
        $this->defaultsPath = rtrim($projectDir, '/').'/config/hatfield.defaults.yaml';
    }

    /**
     * Resolve the full application config for the given project directory.
     *
     * Loads and merges: built-in defaults < home < project.
     * Results are cached by project directory.
     *
     * @param string $projectCwd Target project working directory (defaults to process cwd)
     */
    public function resolve(string $projectCwd = ''): AppConfig
    {
        $cwd = getcwd();
        $key = '' !== $projectCwd ? $projectCwd : (false !== $cwd ? $cwd : '/');

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $config = $this->loader->load($this->defaultsPath, $key);
        $this->cache[$key] = $config;

        return $config;
    }

    /**
     * Clear the resolution cache (useful for tests or theme reload).
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }
}
