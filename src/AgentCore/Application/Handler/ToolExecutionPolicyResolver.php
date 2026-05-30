<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Tool\ToolExecutionSettingsInterface;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionPolicy;

/**
 * Resolves default execution policy from settings.
 *
 * Execution mode is now sourced from tool registration
 * (ToolDefinitionDTO.executionMode) via ActiveToolSet in
 * LlmStepResultHandler. This resolver provides only the
 * global fallback defaults for timeout and parallelism.
 */
final readonly class ToolExecutionPolicyResolver
{
    private ToolExecutionMode $defaultMode;

    public function __construct(
        string $defaultMode,
        private int $defaultTimeoutSeconds,
        private int $maxParallelism,
    ) {
        $this->defaultMode = ToolExecutionMode::tryFrom($defaultMode) ?? ToolExecutionMode::Sequential;
    }

    public static function fromSettings(ToolExecutionSettingsInterface $settings): self
    {
        return new self(
            defaultMode: $settings->defaultMode(),
            defaultTimeoutSeconds: $settings->defaultTimeoutSeconds(),
            maxParallelism: $settings->maxParallelism(),
        );
    }

    public function resolve(string $toolName): ToolExecutionPolicy
    {
        return new ToolExecutionPolicy(
            mode: $this->defaultMode,
            timeoutSeconds: max(1, $this->defaultTimeoutSeconds),
            maxParallelism: max(1, $this->maxParallelism),
        );
    }
}
