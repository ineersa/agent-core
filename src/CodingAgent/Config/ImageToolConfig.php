<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Typed DTO for the tools.image.* settings section.
 *
 * Controls view_image tool limits: maximum file size in bytes, and
 * maximum image dimensions (width and height). Images exceeding any
 * of these limits are rejected with a clear error message.
 *
 * Hydrated by Symfony Serializer/Denormalizer from the merged Hatfield
 * config array (defaults.yaml → home settings → project settings).
 */
final readonly class ImageToolConfig
{
    public const int DEFAULT_MAX_BYTES = 10_485_760;
    public const int DEFAULT_MAX_WIDTH = 4096;
    public const int DEFAULT_MAX_HEIGHT = 2000;

    public function __construct(
        #[SerializedName('max_bytes')]
        public int $maxBytes = self::DEFAULT_MAX_BYTES,

        #[SerializedName('max_width')]
        public int $maxWidth = self::DEFAULT_MAX_WIDTH,

        #[SerializedName('max_height')]
        public int $maxHeight = self::DEFAULT_MAX_HEIGHT,
    ) {
    }

    /**
     * DI factory — extract image settings from AppConfig entity.
     *
     * Used by the Symfony container via services.yaml factory definition
     * so that autowired consumers receive the same instance that lives
     * inside AppConfig.
     */
    public static function fromAppConfig(AppConfig $appConfig): self
    {
        return $appConfig->tools->image;
    }
}
