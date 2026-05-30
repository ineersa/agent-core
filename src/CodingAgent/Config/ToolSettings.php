<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\AgentCore\Contract\Tool\ToolExecutionSettingsInterface;

/**
 * Tool settings resolved from Hatfield config.
 *
 * Hydrated from the `tools.*` keys in the merged Hatfield settings
 * (defaults.yaml → home settings → project settings).
 *
 * @immutable
 */
final readonly class ToolSettings implements ToolExecutionSettingsInterface
{
    public string $mode;
    public int $timeoutSeconds;
    public int $maxParallelism;

    /** @var array<string, array{mode?: string|null, timeout_seconds?: int|null}> */
    public array $overrides;

    public function __construct(
        ?string $mode = null,
        ?int $timeoutSeconds = null,
        ?int $maxParallelism = null,
        array $overrides = [],
    ) {
        $this->mode = $mode ?? ToolExecutionConfig::DEFAULT_MODE;
        $this->timeoutSeconds = $timeoutSeconds ?? ToolExecutionConfig::DEFAULT_TIMEOUT_SECONDS;
        $this->maxParallelism = $maxParallelism ?? ToolExecutionConfig::DEFAULT_MAX_PARALLELISM;
        $this->overrides = $overrides;
    }

    /**
     * Create from AppConfig for DI wiring.
     */
    public static function fromAppConfig(AppConfig $appConfig): self
    {
        $execution = $appConfig->tools->execution;

        return new self(
            mode: $execution->defaultMode,
            timeoutSeconds: $execution->timeoutSeconds,
            maxParallelism: $execution->maxParallelism,
            overrides: $execution->overrides,
        );
    }

    public function defaultMode(): string
    {
        return $this->mode;
    }

    public function defaultTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function maxParallelism(): int
    {
        return $this->maxParallelism;
    }

    public function executionOverrides(): array
    {
        return $this->overrides;
    }
}
