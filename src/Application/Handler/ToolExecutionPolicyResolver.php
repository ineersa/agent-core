<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionPolicy;

/**
 * Resolves execution policies for tools by combining a default configuration with specific overrides. It determines the operational mode, timeout, and parallelism constraints for a given tool name.
 */
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
