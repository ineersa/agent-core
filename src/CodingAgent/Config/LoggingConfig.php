<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Monolog\Level;

/**
 * Logging settings resolved from Hatfield config.
 *
 * Immutable value object. Contains the minimum log level and
 * maximum rotated log files to retain.
 */
final readonly class LoggingConfig
{
    /**
     * @param Level $level    Minimum log level (e.g. Level::Info, Level::Debug)
     * @param int   $maxFiles Maximum rotated log files to keep (daily rotation)
     */
    public function __construct(
        public Level $level = Level::Info,
        public int $maxFiles = 14,
    ) {
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
     * @param array<string, mixed> $data The raw merged config array
     */
    public static function fromArray(array $data): self
    {
        $logging = $data['logging'] ?? [];
        if (!\is_array($logging)) {
            $logging = [];
        }

        $level = self::resolveLevel((string) ($logging['level'] ?? 'info'));
        $maxFiles = $logging['max_files'] ?? null;
        $maxFiles = \is_int($maxFiles) ? $maxFiles : 14;

        return new self(level: $level, maxFiles: $maxFiles);
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
