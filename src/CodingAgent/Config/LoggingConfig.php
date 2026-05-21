<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Monolog\Level;

/**
 * Logging settings resolved from Hatfield config.
 *
 * Immutable value object. Contains the log storage directory path,
 * minimum log level, and maximum rotated log files to retain.
 *
 * The logDir is always an absolute path resolved by {@see AppConfigLoader}
 * from the default {@see logging.path} setting (e.g. {@see .hatfield/logs}
 * resolves to {@see <CWD>/.hatfield/logs}).
 */
final readonly class LoggingConfig
{
    /**
     * Absolute path to the log storage directory, resolved from Hatfield
     * settings (default: {@see <CWD>/.hatfield/logs}).
     */
    public string $logDir;

    /**
     * @param string|null $logDir   Absolute path to the log storage directory.
     *                              Defaults to {@see <CWD>/.hatfield/logs}.
     * @param Level       $level    Minimum log level (e.g. Level::Info, Level::Debug)
     * @param int         $maxFiles Maximum rotated log files to keep (daily rotation)
     */
    public function __construct(
        ?string $logDir = null,
        public Level $level = Level::Info,
        public int $maxFiles = 14,
    ) {
        $this->logDir = $logDir ?? self::resolveDefaultLogDir();
    }

    /**
     * DI factory — extract logging settings from resolved AppConfig.
     *
     * Used by the Symfony container via services.yaml factory definition.
     */
    public static function fromAppConfig(AppConfig $appConfig): self
    {
        return self::fromArray($appConfig->raw);
    }

    /**
     * Create from raw merged Hatfield config (from defaults/home/project).
     *
     * Paths in the input are already resolved by {@see AppConfigLoader::resolveConfigPaths()}.
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
            $logDir = self::resolveDefaultLogDir();
        }

        $level = self::resolveLevel((string) ($logging['level'] ?? 'info'));
        $maxFiles = $logging['max_files'] ?? null;
        $maxFiles = \is_int($maxFiles) ? $maxFiles : 14;

        return new self(logDir: $logDir, level: $level, maxFiles: $maxFiles);
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
        $mapped = match (strtolower($name)) {
            'debug' => Level::Debug,
            'info' => Level::Info,
            'notice' => Level::Notice,
            'warning' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => null,
        };

        return $mapped ?? Level::Info;
    }
}
