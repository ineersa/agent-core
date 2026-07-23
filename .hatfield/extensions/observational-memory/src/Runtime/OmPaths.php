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
        public string $packageRoot,
        public string $consolePath,
    ) {
    }

    public static function fromSettings(OmSettings $settings, string $cwd, string $packageRoot): self
    {
        $path = $settings->databasePath;
        if (!str_starts_with($path, '/')) {
            $path = rtrim($cwd, '/').'/'.ltrim($path, '/');
        }

        $consolePath = rtrim($packageRoot, '/').'/bin/console';
        if (!is_file($consolePath)) {
            throw new \RuntimeException(\sprintf('OM package console not found at %s', $consolePath));
        }

        return new self(
            databasePath: $path,
            dataDirectory: \dirname($path),
            packageRoot: $packageRoot,
            consolePath: $consolePath,
        );
    }
}
