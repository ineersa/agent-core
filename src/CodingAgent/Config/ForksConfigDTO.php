<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Fork subsystem configuration resolved from Hatfield config.
 *
 * Immutable value object. Controls concurrency limits and an optional
 * fork model and thinking-level overrides (null = use the parent session values).
 *
 * Hydrated from the `forks` section of Hatfield merged config via
 * Symfony Serializer denormalization.
 *
 * @see \Ineersa\CodingAgent\Agent\Fork\ForkConfigResolver
 */
final readonly class ForksConfigDTO
{
    public const int DEFAULT_MAX_CONCURRENT = 1;

    public function __construct(
        #[SerializedName('max_concurrent')]
        public int $maxConcurrent = self::DEFAULT_MAX_CONCURRENT,

        /**
         * Optional provider/model override for fork child runs.
         * When null, the parent session model is used.
         */
        public ?string $model = null,

        /**
         * Optional reasoning/thinking level override for fork child runs.
         * When null, the parent session reasoning is used.
         */
        #[SerializedName('thinking_level')]
        public ?string $thinkingLevel = null,
    ) {
    }

    /**
     * Convenience factory from AppConfig (same pattern as CompactionConfig::fromAppConfig).
     */
    public static function fromAppConfig(AppConfig $appConfig): self
    {
        return $appConfig->forks;
    }
}
