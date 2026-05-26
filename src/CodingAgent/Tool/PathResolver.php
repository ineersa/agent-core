<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

final class PathResolver
{
    /**
     * Resolve a filesystem path to an absolute, normalized form.
     *
     * Resolves in this order:
     *  1. Empty path → returns $cwd (or getcwd()).
     *  2. Leading `~` → expands to the current user's home directory.
     *  3. Relative path → joins against $cwd (or getcwd()).
     *  4. Absolute path → used as-is.
     *  5. Normalizes `..`, `.`, and consecutive separators without
     *     requiring the target path to exist on disk.
     *
     * @param string      $path the path to resolve
     * @param string|null $cwd  Working directory for relative-path resolution.
     *                          Defaults to getcwd().
     *
     * @return string the resolved absolute, normalized path
     */
    public static function resolve(string $path, ?string $cwd = null): string
    {
        $cwd ??= (getcwd() ?: '/');

        if ('' === $path) {
            return self::normalize($cwd);
        }

        // Expand leading ~ to the home directory.
        if ('~' === $path[0]) {
            $home = self::getHomeDirectory();
            if ('~' === $path) {
                return $home;
            }
            $path = $home.'/'.substr($path, 2); // skip "~/" (or ~foo → home/foo)
        }

        // Prepend cwd for relative paths.
        if (!self::isAbsolute($path)) {
            $path = $cwd.'/'.$path;
        }

        return self::normalize($path);
    }

    /**
     * Normalize a path by resolving `.` and `..` segments and collapsing
     * duplicate separators.  Does not touch the filesystem.
     */
    private static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        $parts = preg_split('#/+#', $path, -1, \PREG_SPLIT_NO_EMPTY);

        if (false === $parts || [] === $parts) {
            // Path consists only of separators and/or dots → root or cwd.
            return '' !== $path && '/' === $path[0] ? '/' : '.';
        }

        $resolved = [];
        foreach ($parts as $part) {
            if ('.' === $part) {
                continue;
            }
            if ('..' === $part) {
                if ([] !== $resolved) {
                    array_pop($resolved);
                }
                continue;
            }
            $resolved[] = $part;
        }

        // Preserve leading slash for absolute paths.
        $prefix = '/' === $path[0] ? '/' : '';

        $result = $prefix.implode('/', $resolved);

        // Empty result after normalization → root for absolute, "." for relative.
        if ('' === $result) {
            return '' !== $prefix ? '/' : '.';
        }

        return $result;
    }

    /**
     * Determine whether a path is absolute.
     */
    private static function isAbsolute(string $path): bool
    {
        // Unix absolute: starts with /
        if ('/' === $path[0]) {
            return true;
        }

        // Windows absolute: drive letter followed by :\ or :/
        return isset($path[1]) && ':' === $path[1];
    }

    /**
     * Get the current user's home directory.
     *
     * Uses $HOME (Unix) or USERPROFILE (Windows), with a fallback for
     * minimal environments (e.g. containers where HOME may be unset).
     */
    private static function getHomeDirectory(): string
    {
        $home = getenv('HOME');
        if (\is_string($home) && '' !== $home) {
            return $home;
        }

        $home = getenv('USERPROFILE');
        if (\is_string($home) && '' !== $home) {
            return $home;
        }

        // Last-resort fallback.
        return '/home/'.(getenv('USER') ?: 'unknown');
    }
}
