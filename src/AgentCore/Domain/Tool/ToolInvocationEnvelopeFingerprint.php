<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/** Stable fingerprint for rejecting mismatched execution envelopes for one invocation identity. */
final class ToolInvocationEnvelopeFingerprint
{
    /**
     * @param array<string, mixed> $arguments
     */
    public static function compute(
        string $runId,
        int $turnNo,
        string $stepId,
        string $toolCallId,
        string $toolName,
        string $idempotencyKey,
        array $arguments,
        ?string $toolIdempotencyKey = null,
        ?string $mode = null,
    ): string {
        $payload = [
            'run_id' => $runId,
            'turn_no' => $turnNo,
            'step_id' => $stepId,
            'tool_call_id' => $toolCallId,
            'tool_name' => $toolName,
            'idempotency_key' => $idempotencyKey,
            'tool_idempotency_key' => $toolIdempotencyKey,
            'mode' => $mode,
            'arguments' => $arguments,
        ];

        return hash('sha256', json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }

    public static function fromCorrelation(DeferredToolCompletionCorrelation $correlation): string
    {
        return self::compute(
            runId: $correlation->runId,
            turnNo: $correlation->turnNo,
            stepId: $correlation->stepId,
            toolCallId: $correlation->toolCallId,
            toolName: $correlation->toolName,
            idempotencyKey: $correlation->idempotencyKey,
            arguments: $correlation->arguments,
            toolIdempotencyKey: $correlation->toolIdempotencyKey,
            mode: $correlation->mode,
        );
    }
}
