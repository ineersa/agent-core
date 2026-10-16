<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/**
 * Deterministic accepted-terminal receipt identity for one invocation.
 *
 * completion_id is allocated once per original invocation. Matching duplicates
 * reuse it; conflicting fingerprints are invariant violations.
 */
final class ToolInvocationAcceptedCompletionIdentity
{
    public static function completionId(
        string $runId,
        int $turnNo,
        string $stepId,
        string $toolCallId,
    ): string {
        return hash('sha256', json_encode([
            'schema' => 'background-result:v1',
            'run_id' => $runId,
            'turn_no' => $turnNo,
            'step_id' => $stepId,
            'tool_call_id' => $toolCallId,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }

    public static function completionIdFromCorrelation(DeferredToolCompletionCorrelation $correlation): string
    {
        return self::completionId(
            $correlation->runId,
            $correlation->turnNo,
            $correlation->stepId,
            $correlation->toolCallId,
        );
    }

    /**
     * @param array<string, mixed> $normalizedToolResult
     */
    public static function resultFingerprint(array $normalizedToolResult): string
    {
        return hash('sha256', json_encode($normalizedToolResult, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }
}
