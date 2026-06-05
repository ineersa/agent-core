<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Process;

/**
 * Resolves the agent executable for a source checkout layout.
 *
 * Uses the kernel project directory (the directory containing composer.json
 * and bin/console) to build the absolute command array. This is independent
 * of the runtime working directory, so --cwd changes and isolated Hatfield
 * project directories never affect consumer or subprocess binary resolution.
 *
 * Works in all environments where the standard Symfony CLI layout is present:
 *   <project_root>/bin/console
 *   <project_root>/composer.json
 *
 * Does NOT work inside a PHAR — use PharExecutableLocator for that case.
 *
 * @see AppExecutableLocator
 */
final class SourceTreeExecutableLocator implements AppExecutableLocator
{
    private readonly string $consolePath;

    public function __construct(
        /** Kernel project directory, e.g. '%kernel.project_dir%' */
        private readonly string $projectDir,
    ) {
        $this->consolePath = $this->projectDir.'/bin/console';
    }

    public function command(): array
    {
        return [\PHP_BINARY, $this->consolePath];
    }

    public function path(): string
    {
        return $this->consolePath;
    }
}
