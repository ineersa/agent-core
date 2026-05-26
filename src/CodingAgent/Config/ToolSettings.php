<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\AgentCore\Contract\Tool\ToolExecutionSettingsInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

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
     * Create from the `tools.*` section of the merged Hatfield config
     * using Symfony Serializer denormalization.
     *
     * @param array<string, mixed>  $data         The resolved `tools` section array (may be empty)
     * @param DenormalizerInterface $denormalizer Symfony denormalizer for typed DTO hydration
     */
    public static function fromConfigData(array $data, DenormalizerInterface $denormalizer): self
    {
        $executionData = \is_array($data['execution'] ?? null) ? $data['execution'] : [];

        $execution = $denormalizer->denormalize(
            $executionData,
            ToolExecutionConfig::class,
            'array',
        );

        return new self(
            mode: $execution->defaultMode,
            timeoutSeconds: $execution->timeoutSeconds,
            maxParallelism: $execution->maxParallelism,
        );
    }

    /**
     * Create from AppConfig for DI wiring.
     */
    public static function fromAppConfig(AppConfig $appConfig, DenormalizerInterface $denormalizer): self
    {
        $tools = $appConfig->raw['tools'] ?? [];

        return self::fromConfigData(\is_array($tools) ? $tools : [], $denormalizer);
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
