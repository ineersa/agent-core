<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Tool settings resolved from Hatfield config.
 *
 * Hydrated from the `tools.*` keys in the merged Hatfield settings
 * (defaults.yaml → home settings → project settings).
 *
 * @immutable
 */
final readonly class ToolSettings
{
    public const string DEFAULT_MODE = 'sequential';
    public const int DEFAULT_TIMEOUT_SECONDS = 300;
    public const int DEFAULT_MAX_PARALLELISM = 4;
    public const int DEFAULT_TERMINATION_GRACE_SECONDS = 5;

    public string $mode;
    public int $timeoutSeconds;
    public int $maxParallelism;
    public int $terminationGraceSeconds;

    public function __construct(
        ?string $mode = null,
        ?int $timeoutSeconds = null,
        ?int $maxParallelism = null,
        ?int $terminationGraceSeconds = null,
    ) {
        $this->mode = $mode ?? self::DEFAULT_MODE;
        $this->timeoutSeconds = $timeoutSeconds ?? self::DEFAULT_TIMEOUT_SECONDS;
        $this->maxParallelism = $maxParallelism ?? self::DEFAULT_MAX_PARALLELISM;
        $this->terminationGraceSeconds = $terminationGraceSeconds ?? self::DEFAULT_TERMINATION_GRACE_SECONDS;
    }

    /**
     * Create from the `tools.*` section of the merged Hatfield config.
     *
     * Used by the Symfony DI container via AppConfig::raw['tools'].
     *
     * @param array<string, mixed> $data The resolved `tools` section array (may be empty)
     */
    public static function fromConfigData(array $data): self
    {
        $execution = $data['execution'] ?? [];
        if (!\is_array($execution)) {
            $execution = [];
        }

        $process = $data['process'] ?? [];
        if (!\is_array($process)) {
            $process = [];
        }

        return new self(
            mode: self::stringOrNull($execution, 'default_mode'),
            timeoutSeconds: self::intOrNull($execution, 'timeout_seconds'),
            maxParallelism: self::intOrNull($execution, 'max_parallelism'),
            terminationGraceSeconds: self::intOrNull($process, 'terminate_grace_seconds'),
        );
    }

    /**
     * Create from AppConfig::raw['tools'] for DI wiring.
     */
    public static function fromAppConfig(AppConfig $appConfig): self
    {
        $tools = $appConfig->raw['tools'] ?? [];

        return self::fromConfigData(\is_array($tools) ? $tools : []);
    }

    private static function stringOrNull(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    private static function intOrNull(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return \is_int($value) ? $value : (\is_string($value) && ctype_digit($value) ? (int) $value : null);
    }
}
