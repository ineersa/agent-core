<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Per-level fork configuration.
 *
 * Each fork level can optionally override the model and provide a
 * description. When model is null, the session model is used as fallback.
 *
 * Immutable value object. Hydrated from the `forks.levels.<level>` section
 * of Hatfield merged config via Symfony Serializer denormalization.
 */
final readonly class ForkLevelConfigDTO
{
    public const ?string DEFAULT_MODEL = null;
    public const string DEFAULT_DESCRIPTION_JUNIOR = 'Narrow, low-risk tasks (targeted one-shot operations)';
    public const string DEFAULT_DESCRIPTION_MIDDLE = 'General default tasks (~70% of session)';
    public const string DEFAULT_DESCRIPTION_SENIOR = 'Hard, high-risk tasks (maximal autonomy, complex reasoning)';

    /**
     * @param string|null $model       Provider/model override (null = session model fallback)
     * @param string|null $description Human-readable level description
     */
    public function __construct(
        public ?string $model = self::DEFAULT_MODEL,
        public ?string $description = null,
    ) {
    }

    /**
     * Default instance for the Junior level.
     */
    public static function juniorDefault(): self
    {
        return new self(
            model: null,
            description: self::DEFAULT_DESCRIPTION_JUNIOR,
        );
    }

    /**
     * Default instance for the Middle level.
     */
    public static function middleDefault(): self
    {
        return new self(
            model: null,
            description: self::DEFAULT_DESCRIPTION_MIDDLE,
        );
    }

    /**
     * Default instance for the Senior level.
     */
    public static function seniorDefault(): self
    {
        return new self(
            model: null,
            description: self::DEFAULT_DESCRIPTION_SENIOR,
        );
    }
}
