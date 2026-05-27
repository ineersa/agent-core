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
    public const int DEFAULT_MAX_DIMENSION = 2000;
    /** 4.5 MB base64 — safe below Anthropic/OAI 5 MB limits */
    public const int DEFAULT_ENCODED_MAX_BYTES = 4_718_592;
    public const int DEFAULT_JPEG_QUALITY = 80;
    public const int DEFAULT_JPEG_MIN_QUALITY = 40;

    public function __construct(
        #[SerializedName('max_bytes')]
        public int $maxBytes = self::DEFAULT_MAX_BYTES,

        #[SerializedName('max_width')]
        public int $maxWidth = self::DEFAULT_MAX_WIDTH,

        #[SerializedName('max_height')]
        public int $maxHeight = self::DEFAULT_MAX_HEIGHT,

        /**
         * Maximum pixel dimension for resize-to-fit.
         * Images exceeding this are scaled down to fit within a
         * maxDimension × maxDimension bounding box, preserving aspect
         * ratio. This is the resize target, not a rejection limit.
         */
        #[SerializedName('max_dimension')]
        public int $maxDimension = self::DEFAULT_MAX_DIMENSION,

        /**
         * Maximum allowed base64-encoded payload length in bytes.
         * If the image exceeds this after resize, the processor tries
         * quality reduction, format conversion (JPEG/WebP), and
         * progressive dimension reduction to get under the limit.
         */
        #[SerializedName('encoded_max_bytes')]
        public int $encodedMaxBytes = self::DEFAULT_ENCODED_MAX_BYTES,

        /**
         * Starting JPEG/WebP compression quality (1–100).
         */
        #[SerializedName('jpeg_quality')]
        public int $jpegQuality = self::DEFAULT_JPEG_QUALITY,

        /**
         * Minimum JPEG/WebP quality the processor may try when
         * reducing encoded size. Will not go below this value.
         */
        #[SerializedName('jpeg_min_quality')]
        public int $jpegMinQuality = self::DEFAULT_JPEG_MIN_QUALITY,
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
