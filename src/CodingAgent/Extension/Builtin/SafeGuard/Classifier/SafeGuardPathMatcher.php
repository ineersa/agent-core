<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Classifier;

use Ineersa\CodingAgent\Config\SafeGuardConfig;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardPolicy;
use Ineersa\CodingAgent\Path\PathResolver;

/**
 * Faithful PHP port of Pi's path matching logic from policy.ts and safe-guard.ts.
 *
 * Uses PathResolver for robust path resolution (handles ~, .., ., null bytes,
 * backslash normalization, etc.) instead of a custom resolveSegments implementation.
 *
 * Covers:
 * - isInsideCwd: check if a target path is within the working directory
 * - isPathInList: check if a resolved path matches an allowlist entry
 * - isProtectedReadPath: check if a file path matches protected read patterns
 */
final class SafeGuardPathMatcher
{
    /**
     * Check if a target path is inside the current working directory.
     *
     * Mirrors Pi's isInsideCwd() from safe-guard.ts.
     * Uses PathResolver::resolve() to normalize the path.
     */
    public function isInsideCwd(string $cwd, string $targetPath): bool
    {
        $resolved = PathResolver::resolve($targetPath, $cwd);
        $normalizedCwd = PathResolver::resolve($cwd);

        return $resolved === $normalizedCwd
            || \str_starts_with($resolved, $normalizedCwd . '/');
    }

    /**
     * Check if a resolved path is in an allowlist.
     *
     * Mirrors Pi's isPathInList() from policy.ts.
     * Each entry in the list is resolved, then checked for exact match
     * or prefix match (starts with entry + '/').
     *
     * @param list<string> $list
     */
    public function isPathInList(array $list, string $targetPath): bool
    {
        $resolved = PathResolver::resolve($targetPath);

        foreach ($list as $entry) {
            $resolvedEntry = PathResolver::resolve($entry);

            if ($resolved === $resolvedEntry
                || \str_starts_with($resolved, $resolvedEntry . '/')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a file path matches any protected read pattern.
     *
     * Mirrors Pi's isProtectedReadPath() from policy.ts.
     *
     * Matching rules (case-insensitive):
     *   1. Exact basename match
     *   2. Path ends with pattern
     *   3. Path contains pattern as a segment (e.g., "/home/user/.ssh/id_rsa" contains ".ssh/id_")
     */
    public function isProtectedReadPath(SafeGuardPolicy $policy, string $filePath): bool
    {
        $resolved = \mb_strtolower(PathResolver::resolve($filePath));
        $basename = \basename($resolved);

        foreach ($policy->protectedReadPatterns as $pattern) {
            $p = \mb_strtolower($pattern);

            // 1. Exact basename match
            if ($basename === $p) {
                return true;
            }

            // 2. Path ends with pattern
            if (\str_ends_with($resolved, $p)) {
                return true;
            }

            // 3. Path contains pattern as a segment
            if (\str_contains($resolved, '/' . $p)
                || \str_contains($resolved, $p . '/')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the default protected read patterns from SafeGuardConfig.
     *
     * @return list<string>
     */
    public function defaultProtectedReadPatterns(): array
    {
        return SafeGuardConfig::DEFAULT_PROTECTED_READ_PATTERNS;
    }
}
