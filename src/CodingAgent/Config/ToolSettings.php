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
 * Execution mode per tool is set at registration time by the tool
 * author/provider in ToolDefinitionDTO, not from settings overrides.
 *
 * @immutable
 */
final readonly class ToolSettings implements ToolExecutionSettingsInterface
{
    public string $mode;
    public int $timeoutSeconds;
    public int $maxParallelism;

    public function __construct(
        ?string $mode = null,
        ?int $timeoutSeconds = null,
        ?int $maxParallelism = null,
    ) {
        $this->mode = $mode ?? ToolExecutionConfig::DEFAULT_MODE;
        $this->timeoutSeconds = $timeoutSeconds ?? ToolExecutionConfig::DEFAULT_TIMEOUT_SECONDS;
        $this->maxParallelism = $maxParallelism ?? ToolExecutionConfig::DEFAULT_MAX_PARALLELISM;
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
}
