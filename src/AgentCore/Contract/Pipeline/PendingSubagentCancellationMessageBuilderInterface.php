<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Pipeline;

/**
 * Builds parent-visible cancellation text for a pending subagent tool call
 * when ToolCallResultHandler synthesizes tool results during run cancellation.
 */
interface PendingSubagentCancellationMessageBuilderInterface
{
    /**
     * @param array<string, mixed>|null $toolCallInfo assistant metadata tool_call entry when available
     */
    public function buildForPendingSubagent(
        string $parentRunId,
        string $toolCallId,
        ?array $toolCallInfo = null,
    ): ?string;
}
