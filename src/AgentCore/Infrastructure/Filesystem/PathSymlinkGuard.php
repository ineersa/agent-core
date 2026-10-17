<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Filesystem;

/**
 * Rejects filesystem paths whose leaf or ancestors are symbolic links.
 *
 * realpath() alone is insufficient for ownership checks: a directory symlink
 * under an owned name can resolve into a sibling scope while still appearing
 * contained by dirname(realpath(...)).
 */
final class PathSymlinkGuard
{
    /**
     * True when $path or any ancestor at/under $stopAtRealpath is a symlink.
     */
    public static function pathHasSymlinkAncestor(string $path, string $stopAtRealpath): bool
    {
        $current = $path;
        while (true) {
            if (is_link($current)) {
                return true;
            }
            $parent = \dirname($current);
            if ($parent === $current) {
                return false;
            }
            $parentReal = realpath($parent);
            if (false !== $parentReal && ($parentReal === $stopAtRealpath || !str_starts_with($parentReal.'/', $stopAtRealpath.'/'))) {
                return is_link($parent);
            }
            $current = $parent;
        }
    }

    /**
     * Resolve a direct child directory of $canonicalRoot that is not a symlink.
     *
     * Returns null when the child name is missing, redirected, or escapes root.
     */
    public static function resolveDirectChildDirectory(string $canonicalRoot, string $childName): ?string
    {
        if ('' === $childName || str_contains($childName, '/') || str_contains($childName, '\\') || str_contains($childName, "\0")) {
            return null;
        }
        $childPath = $canonicalRoot.'/'.$childName;
        if (is_link($childPath) || !is_dir($childPath)) {
            return null;
        }
        if (self::pathHasSymlinkAncestor($childPath, $canonicalRoot)) {
            return null;
        }
        $childReal = realpath($childPath);
        if (false === $childReal || !is_dir($childReal) || \dirname($childReal) !== $canonicalRoot) {
            return null;
        }

        return $childReal;
    }

    /**
     * Resolve a regular file under $canonicalScope without following redirected ancestry.
     */
    public static function resolveContainedRegularFile(string $canonicalScope, string $path): ?string
    {
        if (is_link($path) || !is_file($path)) {
            return null;
        }
        if (self::pathHasSymlinkAncestor($path, $canonicalScope)) {
            return null;
        }
        $pathReal = realpath($path);
        if (false === $pathReal || !is_file($pathReal) || !str_starts_with($pathReal, $canonicalScope.'/')) {
            return null;
        }

        return $pathReal;
    }
}
