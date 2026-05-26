<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Tool\ToolExecutionSettingsInterface;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionPolicy;

final readonly class ToolExecutionPolicyResolver
{
    private ToolExecutionMode $defaultMode;

    /**
     * Initializes default mode, timeout, parallelism, and override configurations.
     *
     * @param array<string, array{mode?: string|null, timeout_seconds?: int|null}> $overrides
     */
    public function __construct(
        string $defaultMode,
        private int $defaultTimeoutSeconds,
        private int $maxParallelism,
        private array $overrides = [],
    ) {
        $this->defaultMode = ToolExecutionMode::tryFrom($defaultMode) ?? ToolExecutionMode::Sequential;
    }

    /**
     * @param array<string, array{mode?: string|null, timeout_seconds?: int|null}> $overrides
     */
    public static function fromSettings(ToolExecutionSettingsInterface $settings, array $overrides = []): self
    {
        return new self(
            defaultMode: $settings->defaultMode(),
            defaultTimeoutSeconds: $settings->defaultTimeoutSeconds(),
            maxParallelism: $settings->maxParallelism(),
            overrides: $overrides,
        );
    }

    public function resolve(string $toolName): ToolExecutionPolicy
    {
        $override = $this->overrides[$toolName] ?? [];

        $mode = ToolExecutionMode::tryFrom((string) ($override['mode'] ?? $this->defaultMode->value)) ?? $this->defaultMode;
        $timeout = (int) ($override['timeout_seconds'] ?? $this->defaultTimeoutSeconds);

        return new ToolExecutionPolicy(
            mode: $mode,
            timeoutSeconds: max(1, $timeout),
            maxParallelism: max(1, $this->maxParallelism),
        );
    }
}
