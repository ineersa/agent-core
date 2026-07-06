<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\FileRewind;

final readonly class FileRewindConfig
{
    public const bool DEFAULT_ENABLED = true;
    public const int DEFAULT_MAX_RETAINED_TURNS = 100;
    public const int DEFAULT_MAX_FILE_BYTES = 2_097_152;
    public const int DEFAULT_GIT_TIMEOUT_SECONDS = 30;

    public function __construct(
        public bool $enabled = self::DEFAULT_ENABLED,
        public int $maxRetainedTurns = self::DEFAULT_MAX_RETAINED_TURNS,
        public int $maxFileBytes = self::DEFAULT_MAX_FILE_BYTES,
        public int $gitTimeoutSeconds = self::DEFAULT_GIT_TIMEOUT_SECONDS,
    ) {
    }

    /** @param array<string, mixed> $settings */
    public static function fromSettings(array $settings): self
    {
        return new self(
            enabled: (bool) ($settings['enabled'] ?? self::DEFAULT_ENABLED),
            maxRetainedTurns: max(1, (int) ($settings['max_retained_turns'] ?? self::DEFAULT_MAX_RETAINED_TURNS)),
            maxFileBytes: max(1, (int) ($settings['max_file_bytes'] ?? self::DEFAULT_MAX_FILE_BYTES)),
            gitTimeoutSeconds: max(1, (int) ($settings['git_timeout_seconds'] ?? self::DEFAULT_GIT_TIMEOUT_SECONDS)),
        );
    }
}
