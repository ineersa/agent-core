<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\DTO;

use Ineersa\CodingAgent\Config\SettingsLayerEnum;

/**
 * Outcome of installing one curated Composer extension package into a settings layer root.
 *
 * Does not record enablement or settings writes — callers own those separately after success.
 */
final readonly class ExtensionPackageInstallResultDTO
{
    public function __construct(
        public SettingsLayerEnum $layer,
        public string $root,
        public string $package,
        public string $version,
        public bool $alreadyInstalled,
    ) {
    }
}
