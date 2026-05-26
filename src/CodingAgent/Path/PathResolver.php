<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Path;

/**
 * Resolve a filesystem path to an absolute, normalized form.
 *
 * Intended for use by CodingAgent file tools (read, write, edit, view_image).
 * This is a standalone static utility with no dependencies on other Hatfield
 * services, AgentCore, or Tui.
 *
 * For settings-path resolution with placeholder expansion (e.g.
 * %kernel.project_dir%), see Ineersa\CodingAgent\Config\SettingsPathResolver.
 *
 * Resolution order:
 *  1. Empty path → returns $cwd.
 *  2. Null byte in path or cwd → throws \InvalidArgumentException.
 *  3. Leading `~` → expands to home directory (only bare `~` and `~/` are valid;
 *     `~user` is rejected).
 *  4. Relative path (does not start with `/`) → joined against $cwd.
 *  5. $cwd must be absolute when provided; relative $cwd is rejected.
 *  6. Normalized: `.` and `..` segments, duplicate separators collapsed.
 *
 * @see \Ineersa\CodingAgent\Config\SettingsPathResolver for settings-oriented
 *      path resolution with placeholder expansion.
 */
final class PathResolver
{
    /**
     * Resolve a filesystem path to an absolute, normalized form.
     *
     * @param string      $path the path to resolve
     * @param string|null $cwd  Working directory for relative-path resolution.
     *                          Must be an absolute path when provided.
     *                          Defaults to getcwd().
     *
     * @return string the resolved absolute, normalized path
     *
     * @throws \InvalidArgumentException if $path or $cwd contain null bytes,
     *                                   if $cwd is provided and relative,
     *                                   or if $path uses unsupported `~user` syntax
     */
    public static function resolve(string $path, ?string $cwd = null): string
    {
        // Reject null bytes in both inputs
        if (str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Path must not contain null bytes.');
        }

        $cwd ??= (getcwd() ?: '/');

        // Treat empty-string cwd the same as null — use the runtime cwd
        if ('' === $cwd) {
            $cwd = getcwd() ?: '/';
        }

        if (str_contains($cwd, "\0")) {
            throw new \InvalidArgumentException('Working directory must not contain null bytes.');
        }

        // cwd must be absolute when provided
        if ('' !== $cwd && '/' !== $cwd[0]) {
            throw new \InvalidArgumentException(\sprintf('Working directory must be absolute, got "%s".', $cwd));
        }

        if ('' === $path) {
            return self::normalize($cwd);
        }

        // Expand leading ~ to the home directory.
        if ('~' === $path[0]) {
            $home = self::getHomeDirectory();

            // Bare ~ → home, normalized
            if ('~' === $path) {
                return self::normalize($home);
            }

            // ~/... → home + rest
            if ('/' === $path[1]) {
                return self::normalize($home.'/'.substr($path, 2));
            }

            // Reject ~user syntax (letter/digit after ~), but allow
            // tilde-starting filenames like ~~ or ~.foo as relative paths.
            if ('' !== $path[1] && '/' !== $path[1]) {
                // Check only the first path segment after ~ (up to / or EOS)
                $segment = str_contains($path, '/')
                    ? substr($path, 1, strpos($path, '/') - 1)
                    : substr($path, 1);

                // A username-like first segment is ~user syntax; reject it
                if ('' !== $segment && 1 === preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*$/', $segment)) {
                    throw new \InvalidArgumentException(\sprintf('Tilde expansion only supports bare ~ and ~/path syntax, got "%s".', $path));
                }
            }

            // Anything else starting with ~ (e.g. ~~, ~.foo) is a regular
            // relative-path name, not tilde expansion — fall through.
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
     *
     * On Unix: starts with `/`.
     * On Windows: starts with a drive letter followed by :/ or :\.
     * Bare colon paths like "a:b" or "C:foo" are NOT treated as absolute
     * on any platform (the latter is only absolute when followed by a
     * separator).
     */
    private static function isAbsolute(string $path): bool
    {
        // Unix absolute: starts with /
        if ('/' === $path[0]) {
            return true;
        }

        // Windows absolute: drive letter followed by :/ or :\
        if (isset($path[1]) && ':' === $path[1]) {
            // Must have a separator after the colon: C:/ or C:\
            return isset($path[2]) && ('/' === $path[2] || '\\' === $path[2]);
        }

        return false;
    }

    /**
     * Get the current user's home directory.
     *
     * Resolution order (matching SettingsPathResolver):
     *  1. $HOME environment variable
     *  2. $USERPROFILE environment variable (Windows)
     *  3. posix_getpwuid() system call
     *  4. Fallback to /tmp
     *
     * @see \Ineersa\CodingAgent\Config\SettingsPathResolver for details
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

        // System-level fallback: query the user database
        if (\function_exists('posix_getpwuid')) {
            $pwInfo = posix_getpwuid(posix_getuid());
            $pwDir = \is_array($pwInfo) ? ($pwInfo['dir'] ?? null) : null;
            if (\is_string($pwDir) && '' !== $pwDir) {
                return $pwDir;
            }
        }

        return '/tmp';
    }
}
