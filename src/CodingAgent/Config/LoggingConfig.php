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
 * The logDir is always an absolute path resolved by {@see AppConfigLoader}
 * from the default {@see logging.path} setting (e.g. {@see .hatfield/logs}
 * resolves to {@see <CWD>/.hatfield/logs}).
 *
 * Hydrated from the logging section of Hatfield merged config via
 * Symfony Serializer in {@see AppConfig::fromContainer()}.
 */
final readonly class LoggingConfig
{
    /**
     * Absolute path to the log storage directory, resolved from Hatfield
     * settings (default: {@see <CWD>/.hatfield/logs}).
     */
    public string $logDir;

    /**
     * Minimum log level.
     */
    public Level $level;

    /**
     * Maximum rotated log files to keep (daily rotation).
     */
    public int $maxFiles;

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
        string $logDir = '',
        #[SerializedName('level')]
        string|Level $level = Level::Info,
        #[SerializedName('max_files')]
        int $maxFiles = 14,
    ) {
        $this->logDir = '' !== $logDir ? $logDir : self::resolveDefaultLogDir();
        $this->level = $level instanceof Level ? $level : self::resolveLevel($level);
        $this->maxFiles = $maxFiles;
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

    /**
     * Create from raw merged Hatfield config (from defaults/home/project).
     *
     * @deprecated Use Symfony Serializer denormalization instead. Kept for
     *             backward compat with existing test constructors.
     *
     * @param array<string, mixed> $data The resolved merged config array
     */
    public static function fromArray(array $data): self
    {
        $logging = $data['logging'] ?? [];
        if (!\is_array($logging)) {
            $logging = [];
        }

        $logDir = $logging['path'] ?? null;
        if (!\is_string($logDir) || '' === $logDir) {
            $logDir = '';
        }

        $level = $logging['level'] ?? 'info';
        $maxFiles = $logging['max_files'] ?? null;
        $maxFiles = \is_int($maxFiles) ? $maxFiles : 14;

        return new self(logDir: $logDir, level: (string) $level, maxFiles: $maxFiles);
    }

    /**
     * Default log directory: CWD/.hatfield/logs.
     *
     * Used as a fallback when logging.path is not present in the resolved
     * config data (should not happen in production — defaults are always
     * loaded).
     */
    private static function resolveDefaultLogDir(): string
    {
        $cwd = getcwd();

        return (false !== $cwd ? $cwd : '/tmp').'/.hatfield/logs';
    }

    /**
     * Resolve a string level name to a Monolog Level enum case.
     *
     * Supports PSR-3 level names (lowercase) and Monolog Level names.
     */
    private static function resolveLevel(string $name): Level
    {
        return match (strtolower($name)) {
            'debug' => Level::Debug,
            'info' => Level::Info,
            'notice' => Level::Notice,
            'warning' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => Level::Info,
        };
    }
}
