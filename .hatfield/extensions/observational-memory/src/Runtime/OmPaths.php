<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Runtime;

/**
 * Resolves extension-owned filesystem paths against Hatfield CWD.
 */
final readonly class OmPaths
{
    public function __construct(
        public string $databasePath,
        public string $dataDirectory,
    ) {
    }

    public static function fromSettings(OmSettings $settings, string $cwd): self
    {
        $path = $settings->databasePath;
        if (!str_starts_with($path, '/')) {
            $path = rtrim($cwd, '/').'/'.ltrim($path, '/');
        }

        return new self(
            databasePath: $path,
            dataDirectory: \dirname($path),
        );
    }
}
