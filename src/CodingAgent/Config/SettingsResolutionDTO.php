<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Fresh settings resolution from disk: raw layers and merged effective config.
 *
 * Effective known sections are denormalized through Symfony Serializer in
 * {@see AppConfig::fromContainer}. Raw sparse layers intentionally remain arrays:
 * Serializer DTO defaults cannot distinguish an absent key from an explicit null
 * or override and would discard dynamic/unknown keys needed for provenance.
 *
 * Path/provenance queries belong on {@see SettingsValueResolver}, not this DTO.
 */
final readonly class SettingsResolutionDTO
{
    /**
     * @param array<string, mixed> $defaultsRaw
     * @param array<string, mixed> $userRaw
     * @param array<string, mixed> $projectRaw
     * @param array<string, mixed> $effective
     */
    public function __construct(
        public array $defaultsRaw,
        public array $userRaw,
        public array $projectRaw,
        public array $effective,
    ) {
    }
}
