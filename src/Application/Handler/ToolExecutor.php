<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\AgentCore\Domain\Tool\ToolResult;

/**
 * Stage 00 policy-aware placeholder.
 *
 * Real tool execution workflow (including interrupt/parallel orchestration)
 * is implemented in stage 06.
 */
final class ToolExecutor implements ToolExecutorInterface
{
    private readonly ToolExecutionMode $defaultMode;

    /**
     * @param array<string, array{mode?: string|null, timeout_seconds?: int|null}> $overrides
     */
    public function __construct(
        string $defaultMode,
        private readonly int $defaultTimeoutSeconds,
        private readonly int $maxParallelism,
        private readonly array $overrides = [],
    ) {
        $this->defaultMode = ToolExecutionMode::tryFrom($defaultMode) ?? ToolExecutionMode::Sequential;
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        $policy = $this->policyFor($toolCall->toolName);

        return new ToolResult(
            toolCallId: $toolCall->toolCallId,
            toolName: $toolCall->toolName,
            content: [[
                'type' => 'text',
                'text' => \sprintf(
                    'Tool "%s" execution is not implemented yet (mode=%s, timeout=%ds, max_parallelism=%d).',
                    $toolCall->toolName,
                    $policy['mode']->value,
                    $policy['timeout_seconds'],
                    $this->maxParallelism,
                ),
            ]],
            details: [
                'mode' => $policy['mode']->value,
                'timeout_seconds' => $policy['timeout_seconds'],
                'max_parallelism' => $this->maxParallelism,
            ],
            isError: true,
        );
    }

    /**
     * @return array{mode: ToolExecutionMode, timeout_seconds: int}
     */
    private function policyFor(string $toolName): array
    {
        $override = $this->overrides[$toolName] ?? [];

        $mode = ToolExecutionMode::tryFrom((string) ($override['mode'] ?? $this->defaultMode->value)) ?? $this->defaultMode;

        $timeout = (int) ($override['timeout_seconds'] ?? $this->defaultTimeoutSeconds);

        return [
            'mode' => $mode,
            'timeout_seconds' => max(1, $timeout),
        ];
    }
}
