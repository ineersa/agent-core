<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * File rewind (hidden-git checkpoint) settings from Hatfield merged config.
 */
final readonly class FileRewindConfig
{
    public const bool DEFAULT_ENABLED = true;
    public const int DEFAULT_MAX_RETAINED_TURNS = 100;
    public const int DEFAULT_MAX_FILE_BYTES = 2_097_152;

    public function __construct(
        public bool $enabled = self::DEFAULT_ENABLED,

        #[SerializedName('max_retained_turns')]
        public int $maxRetainedTurns = self::DEFAULT_MAX_RETAINED_TURNS,

        #[SerializedName('max_file_bytes')]
        public int $maxFileBytes = self::DEFAULT_MAX_FILE_BYTES,
    ) {
    }

    public static function fromAppConfig(AppConfig $appConfig): self
    {
        $section = $appConfig->raw['rewind']['file_snapshots'] ?? [];

        if (!\is_array($section)) {
            return new self();
        }

        return new self(
            enabled: (bool) ($section['enabled'] ?? self::DEFAULT_ENABLED),
            maxRetainedTurns: max(1, (int) ($section['max_retained_turns'] ?? self::DEFAULT_MAX_RETAINED_TURNS)),
            maxFileBytes: max(1, (int) ($section['max_file_bytes'] ?? self::DEFAULT_MAX_FILE_BYTES)),
        );
    }
}
