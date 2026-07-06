<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\FileRewind;

/**
 * Path safety and inclusion rules for file rewind snapshots.
 */
final class RewindPathScope
{
    /** @var list<string> */
    private const HATFIELD_EXCLUDE_PREFIXES = [
        '.hatfield/sessions/',
        '.hatfield/tmp/',
        '.hatfield/cache/',
        '.hatfield/logs/',
        '.hatfield/rewind/',
    ];

    private readonly string $projectRoot;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = str_replace('\\', '/', rtrim(false !== realpath($projectRoot) ? realpath($projectRoot) : $projectRoot, '/'));
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    public function isInsideProjectRoot(string $relativePath): bool
    {
        $relativePath = str_replace('\\', '/', ltrim($relativePath, '/'));
        if (str_contains($relativePath, '..')) {
            return false;
        }

        $full = $this->projectRoot.'/'.$relativePath;
        $real = realpath($full);
        if (false === $real) {
            $parent = realpath(\dirname($full));

            return false !== $parent && str_starts_with($parent.'/', $this->projectRoot.'/');
        }

        return str_starts_with(str_replace('\\', '/', $real).'/', $this->projectRoot.'/')
            || $real === $this->projectRoot;
    }

    public function shouldExcludeRelativePath(string $relativePath): bool
    {
        $relativePath = str_replace('\\', '/', ltrim($relativePath, '/'));
        if ('' === $relativePath || '.git' === $relativePath || str_starts_with($relativePath, '.git/')) {
            return true;
        }

        foreach (self::HATFIELD_EXCLUDE_PREFIXES as $prefix) {
            if ($relativePath === rtrim($prefix, '/') || str_starts_with($relativePath, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
