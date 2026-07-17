<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Background process settings resolved from Hatfield config.
 *
 * Immutable value object. Contains the storage directory for background
 * process state/logs, retention duration for stale-file cleanup, stop
 * grace period for process termination, and log tail character cap.
 *
 * Hydrated from the tools.background_process section of Hatfield merged
 * config via Symfony Serializer. The storageDir is made absolute by
 * {@see AppConfigLoader::load()} before DTO construction.
 */
final readonly class BackgroundProcessConfig
{
    public function __construct(
        #[SerializedName('path')]
        public string $storageDir = '.hatfield/tmp/bg',

        #[SerializedName('retention')]
        public int $retentionSeconds = 86400,

        #[SerializedName('stop_grace_seconds')]
        public int $stopGraceSeconds = 5,

        #[SerializedName('log_tail_chars')]
        public int $logTailChars = 5000,
    ) {
    }

    /**
     * DI factory — extract background-process settings from AppConfig entity.
     *
     * Used by the Symfony container via services.yaml factory definition
     * so that autowired consumers receive the same instance that lives
     * inside AppConfig.
     */
    public static function fromAppConfig(AppConfig $appConfig): self
    {
        return $appConfig->tools->backgroundProcess;
    }
}
