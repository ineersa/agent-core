<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Monolog\Level;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Logging settings resolved from Hatfield config.
 *
 * Immutable value object. Contains the log storage directory path,
 * minimum log level, and maximum rotated log files to retain.
 *
 * The logDir is always an absolute path resolved by {@see SettingsResolver}
 * from the default {@see logging.path} setting (e.g. {@see .hatfield/logs}
 * resolves to {@see <CWD>/.hatfield/logs}).
 *
 * Hydrated from the logging section of Hatfield merged config via
 * Symfony Serializer in {@see AppConfig::fromContainer()}.
 */
final readonly class LoggingConfig
{
    /**
     * @param string       $logDir   Absolute path to the log storage directory.
     *                               Defaults to {@see <CWD>/.hatfield/logs}.
     * @param string|Level $level    Minimum log level. Accepts a PSR-3 level
     *                               name (e.g. 'info', 'debug') or a Monolog
     *                               Level enum. Defaults to Level::Info.
     * @param int          $maxFiles Maximum rotated log files to retain
     */
    public function __construct(
        #[SerializedName('path')]
        public string $logDir = '',
        #[SerializedName('level')]
        public string|Level $level = Level::Info,
        #[SerializedName('max_files')]
        public int $maxFiles = 14,
    ) {
    }

    /**
     * DI factory — extract logging settings from AppConfig entity.
     *
     * Used by the Symfony container via services.yaml factory definition
     * so that autowired consumers receive the same instance that lives
     * inside AppConfig.
     */
    public static function fromAppConfig(AppConfig $appConfig): self
    {
        return $appConfig->logging;
    }
}
