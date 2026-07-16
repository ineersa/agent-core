<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Fresh settings resolution from disk: raw layers and merged effective config.
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

    public function getValue(string $dottedPath): SettingsValueDTO
    {
        return SettingsDottedPathQuery::query(
            $this->defaultsRaw,
            $this->userRaw,
            $this->projectRaw,
            $this->effective,
            $dottedPath,
        );
    }
}
