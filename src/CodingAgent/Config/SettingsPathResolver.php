<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\CodingAgent\Path\PathResolver;

/**
 * Resolves path patterns in Hatfield settings to absolute filesystem paths.
 *
 * Supported patterns:
 *  - %kernel.project_dir%  Replaced with the app installation directory
 *  - ~                     Replaced with home directory (via PathResolver)
 *  - relative paths        Resolved against $baseDir (via PathResolver)
 *
 * After placeholder expansion, final resolution (tilde, relative, absolute,
 * normalization) is delegated to {@see PathResolver::resolve()}.
 *
 * Design note:
 *  Relative paths in home settings resolve relative to $homeDir.
 *  Relative paths in project settings resolve relative to the caller-provided
 *  base dir (typically the project cwd).
 *  This is determined by the caller, not by this resolver.
 */
final class SettingsPathResolver
{
    private readonly string $homeDir;

    public function __construct(
        private readonly string $appRoot,
        ?string $homeDir = null,
    ) {
        // Resolve home directory: explicit arg > HOME env var > getpwuid > /tmp
        $envHome = getenv('HOME');
        $pwHome = null;
        if (\function_exists('posix_getpwuid')) {
            $pwInfo = posix_getpwuid(posix_getuid());
            $pwHome = \is_array($pwInfo) ? $pwInfo['dir'] : null;
        }
        $this->homeDir = $homeDir
            ?? (false !== $envHome && '' !== $envHome ? $envHome : null)
            ?? $pwHome
            ?? '/tmp';
    }

    /**
     * Resolve a single path, expanding known placeholders.
     *
     * 1. Expands %kernel.project_dir% placeholder.
     * 2. Delegates final resolution (tilde, relative, absolute, normalization)
     *    to {@see PathResolver::resolve()}, which provides null-byte rejection,
     *    strict absolute-path detection, tilde expansion, dot-dot normalization,
     *    and correct relative-path joining.
     *
     * An empty string path is returned as-is (no resolution).
     *
     * @param string $baseDir Directory to resolve relative paths against
     */
    public function resolve(string $path, string $baseDir): string
    {
        if ('' === $path) {
            return '';
        }

        // Expand known placeholders
        $resolved = str_replace(
            ['%kernel.project_dir%'],
            [$this->appRoot],
            $path,
        );

        // Expand tilde using our own home directory resolution.
        // We keep this in SettingsPathResolver rather than delegating
        // to PathResolver because tests control the homeDir explicitly.
        if (str_starts_with($resolved, '~')) {
            $resolved = $this->homeDir.substr($resolved, 1);
        }

        // Delegate final resolution and normalization to PathResolver.
        // This handles relative-path joining, dot-segment normalization,
        // null-byte rejection, and strict absolute-path detection.
        // PathResolver's own tilde expansion is bypassed by the manual
        // expansion above.
        return PathResolver::resolve($resolved, $baseDir);
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

    /**
     * The application installation root (value of %kernel.project_dir%).
     */
    public function getAppRoot(): string
    {
        return $this->appRoot;
    }

    public function getHomeDir(): string
    {
        return $this->homeDir;
    }
}
