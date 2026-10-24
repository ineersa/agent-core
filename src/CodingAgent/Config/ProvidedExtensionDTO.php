<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * One curated Hatfield-provided extension from config/hatfield-provided-extensions.yaml.
 *
 * Built-in entries (SafeGuard) have no Composer package. Installable entries pin an
 * exact Packagist version for first-time installs only.
 */
final readonly class ProvidedExtensionDTO
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $builtin,
        public string $extensionClass,
        public ?string $package = null,
        public ?string $version = null,
    ) {
    }
}
