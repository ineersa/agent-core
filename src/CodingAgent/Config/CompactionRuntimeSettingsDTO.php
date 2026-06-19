<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Effective compaction settings resolved for a specific active model,
 * after applying provider and model overrides.
 *
 * Returned by {@see CompactionConfig::resolveRuntimeSettings()}.
 */
final readonly class CompactionRuntimeSettingsDTO
{
    public function __construct(
        public bool $autoEnabled,
        public int $compactAfterTokens,
        public int $keepRecentTokens,
        public ?string $model,
        public ?string $thinkingLevel,
    ) {
    }
}
