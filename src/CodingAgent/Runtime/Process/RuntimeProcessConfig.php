<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Process;

/**
 * Centralizes app executable resolution and runtime working directory
 * for spawning controller and messenger consumer subprocesses.
 *
 * Provides the app executable command/path (from AppExecutableLocator)
 * and the canonical runtime CWD (from %app.cwd% / HATFIELD_CWD) so that
 * subprocess-spawning code never needs ambient getcwd() or fallback
 * SourceTreeExecutableLocator construction with dirname(__DIR__, 4).
 *
 * $runtimeCwd must be injected from %app.cwd% in production (via
 * services.yaml); the constructor does not fall back to getcwd().
 *
 * Future PHAR packaging only needs a different AppExecutableLocator
 * implementation; this context class and all callers stay unchanged.
 */
final class RuntimeProcessConfig
{
    public function __construct(
        private readonly AppExecutableLocator $executableLocator,
        private readonly string $runtimeCwd = '',
    ) {
        if ('' === $this->runtimeCwd) {
            throw new \InvalidArgumentException('RuntimeProcessConfig requires a non-empty $runtimeCwd. Use %app.cwd% in DI configuration.');
        }
    }

    /**
     * Absolute path to the app executable script, e.g. '/path/to/bin/console'.
     */
    public function executablePath(): string
    {
        return $this->executableLocator->path();
    }

    /**
     * Command array to run the app executable, e.g. [PHP_BINARY, '/path/to/bin/console'].
     *
     * @return list<string>
     */
    public function executableCommand(): array
    {
        return $this->executableLocator->command();
    }

    /**
     * Canonical runtime working directory for the agent session.
     *
     * This is the Hatfield project CWD where settings, sessions, logs,
     * and the runtime DB (messenger.sqlite) are resolved. It is NOT
     * the app installation directory (kernel.project_dir).
     */
    public function runtimeCwd(): string
    {
        return $this->runtimeCwd;
    }
}
