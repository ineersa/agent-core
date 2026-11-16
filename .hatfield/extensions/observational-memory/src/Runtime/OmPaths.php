<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Runtime;

use Symfony\Component\Filesystem\Path;

/**
 * Resolves extension-owned filesystem paths against Hatfield CWD.
 */
final readonly class OmPaths
{
    public function __construct(
        public string $databasePath,
    ) {
    }

    /** The OM storage root may differ from the Git checkout (for example a shared worktree store). */
    public function projectRoot(string $cwd): string
    {
        $marker = strpos($this->databasePath, '/.hatfield/');
        $root = false === $marker ? $cwd : substr($this->databasePath, 0, $marker);
        if ('' === $root) {
            $root = '/';
        }
        $resolved = realpath($root);

        return Path::canonicalize(false === $resolved ? Path::makeAbsolute($root, $cwd) : $resolved);
    }

    public static function fromSettings(OmSettings $settings, string $cwd): self
    {
        $path = $settings->databasePath;
        if (!str_starts_with($path, '/')) {
            $path = rtrim($cwd, '/').'/'.ltrim($path, '/');
        }

        return new self(
            databasePath: $path,
        );
    }
}
