<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Fork subsystem configuration resolved from Hatfield config.
 *
 * Immutable value object. Controls concurrency limits, default fork
 * level, and per-level overrides (model, description).
 *
 * Hydrated from the `forks` section of Hatfield merged config via
 * Symfony Serializer denormalization.
 *
 * @see \Ineersa\CodingAgent\Agent\Fork\ForkConfigResolver
 */
final readonly class ForksConfigDTO
{
    public const int DEFAULT_MAX_CONCURRENT = 1;
    public const ForkLevelEnum DEFAULT_LEVEL = ForkLevelEnum::Middle;

    public const string LEVEL_KEY_JUNIOR = 'junior';
    public const string LEVEL_KEY_MIDDLE = 'middle';
    public const string LEVEL_KEY_SENIOR = 'senior';

    /**
     * @param int                               $maxConcurrent Maximum concurrent fork processes
     * @param ForkLevelEnum                     $defaultLevel  Default level when none is explicitly requested
     * @param array<string, ForkLevelConfigDTO> $levels        Per-level config map keyed by level name
     */
    public function __construct(
        #[SerializedName('max_concurrent')]
        public int $maxConcurrent = self::DEFAULT_MAX_CONCURRENT,

        #[SerializedName('default_level')]
        public ForkLevelEnum $defaultLevel = self::DEFAULT_LEVEL,

        /**
         * Per-level configuration map.
         *
         * Keys are level names (junior, middle, senior).
         * Each value is a ForkLevelConfigDTO with optional model override
         * and description.
         *
         * @var array<string, ForkLevelConfigDTO>
         */
        public array $levels = [],
    ) {
    }

    /**
     * Convenience factory from AppConfig (same pattern as CompactionConfig::fromAppConfig).
     */
    public static function fromAppConfig(AppConfig $appConfig): self
    {
        return $appConfig->forks;
    }

    /**
     * Default configuration with all three levels populated.
     *
     * Used when no `forks` config section is present.
     */
    public static function defaultInstance(): self
    {
        /** @var array<string, ForkLevelConfigDTO> $levels */
        $levels = [
            self::LEVEL_KEY_JUNIOR => ForkLevelConfigDTO::juniorDefault(),
            self::LEVEL_KEY_MIDDLE => ForkLevelConfigDTO::middleDefault(),
            self::LEVEL_KEY_SENIOR => ForkLevelConfigDTO::seniorDefault(),
        ];

        return new self(
            maxConcurrent: self::DEFAULT_MAX_CONCURRENT,
            defaultLevel: self::DEFAULT_LEVEL,
            levels: $levels,
        );
    }

    /**
     * Get the config for a specific level, falling back to the default
     * if not explicitly configured.
     */
    public function levelConfig(ForkLevelEnum $level): ForkLevelConfigDTO
    {
        return $this->levels[$level->value]
            ?? match ($level) {
                ForkLevelEnum::Junior => ForkLevelConfigDTO::juniorDefault(),
                ForkLevelEnum::Middle => ForkLevelConfigDTO::middleDefault(),
                ForkLevelEnum::Senior => ForkLevelConfigDTO::seniorDefault(),
            };
    }
}
