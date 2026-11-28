<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Background process settings resolved from Hatfield config.
 *
 * Immutable value object. Contains the storage directory for background
 * process state/logs, stop grace period for process termination, and log tail
 * character cap.
 *
 * Hydrated from the tools.background_process section of Hatfield merged
 * config via Symfony Serializer. The storage directory is derived by {@see AppConfig} from the canonical cwd.
 */
final readonly class BackgroundProcessConfig
{
    public const string STORAGE_PATH = '.hatfield/tmp/bg';

    public function __construct(
        #[SerializedName('path')]
        public string $storageDir = self::STORAGE_PATH,

        public int $stopGraceSeconds = 5,

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
