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
 * Future PHAR packaging only needs a different AppExecutableLocator
 * implementation; this context class and all callers stay unchanged.
 */
final class RuntimeProcessConfig
{
    private readonly string $runtimeCwd;

    public function __construct(
        private readonly AppExecutableLocator $executableLocator,
        ?string $runtimeCwd = null,
    ) {
        // Resolve runtime working directory from injected value (container
        // parameter %app.cwd%) or fall back to actual getcwd(). The fallback
        // covers the bootstrap boundary where the container is not yet
        // available (e.g. tests constructing this manually, or PHP 8.4+
        // getcwd() returning the kernel project dir from compile-time).
        // Resolve runtime working directory from injected value (container
        // parameter %app.cwd%) or fall back to actual getcwd(). The fallback
        // covers the bootstrap boundary where the container is not yet
        // available (e.g. tests constructing this manually, or PHP 8.4+
        // getcwd() returning the kernel project dir from compile-time).
        if (null !== $runtimeCwd) {
            $this->runtimeCwd = $runtimeCwd;
        } else {
            $cwd = getcwd();
            if (false === $cwd) {
                throw new \RuntimeException('No current working directory available. Set $runtimeCwd explicitly or ensure getcwd() succeeds.');
            }
            $this->runtimeCwd = $cwd;
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
     */
    /** @return list<string> */
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
