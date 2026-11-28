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
 * Only the log-tail budget is hydrated from tools.background_process settings.
 * Stop grace is internal policy; AppConfig derives storage from canonical cwd.
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
