<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Resolves path patterns in Hatfield settings to absolute filesystem paths.
 *
 * Supported patterns:
 *  - %kernel.project_dir%  Replaced with the app installation directory
 *  - ~                     Replaced with $homeDir
 *  - relative paths        Resolved against $cwd (project directory)
 *
 * Design note:
 *  Relative paths in home settings resolve relative to $homeDir.
 *  Relative paths in project settings resolve relative to $projectDir (cwd).
 *  This is determined by the caller, not by this resolver.
 */
final class SettingsPathResolver
{
    private readonly string $homeDir;

    public function __construct(
        private readonly string $projectDir,
        ?string $homeDir = null,
    ) {
        // Resolve home directory: explicit arg > HOME env var > getpwuid > /tmp
        $envHome = getenv('HOME');
        $pwHome = null;
        if (\function_exists('posix_getpwuid')) {
            $pwInfo = posix_getpwuid(posix_getuid());
            $pwHome = \is_array($pwInfo) ? ($pwInfo['dir'] ?? null) : null;
        }
        $this->homeDir = $homeDir
            ?? (false !== $envHome && '' !== $envHome ? $envHome : null)
            ?? $pwHome
            ?? '/tmp';
    }

    /**
     * Resolve a single path, expanding known placeholders.
     *
     * The $baseDir is used for relative paths and should typically
     * be the directory of the settings file that contained this path.
     *
     * @param string $baseDir Directory to resolve relative paths against
     */
    public function resolve(string $path, string $baseDir): string
    {
        // Expand known placeholders
        $resolved = str_replace(
            ['%kernel.project_dir%'],
            [$this->projectDir],
            $path,
        );

        // Expand tilde
        if (str_starts_with($resolved, '~')) {
            $resolved = $this->homeDir.substr($resolved, 1);
        }

        // Resolve relative paths against the provided base dir
        if ('' !== $resolved && !str_starts_with($resolved, '/')) {
            $resolved = rtrim($baseDir, '/').'/'.$resolved;
        }

        return $resolved;
    }

    /**
     * Resolve a list of paths using the given base directory.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    public function resolveList(array $paths, string $baseDir): array
    {
        return array_map(
            fn (string $path): string => $this->resolve($path, $baseDir),
            $paths,
        );
    }

    public function getProjectDir(): string
    {
        return $this->projectDir;
    }

    public function getHomeDir(): string
    {
        return $this->homeDir;
    }
}
