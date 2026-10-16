<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/**
 * Exact readiness-to-correlation binding checks for background acknowledgment.
 *
 * Never infers backgroundability from tool mode, name, PID, or returned JSON.
 * Producers and capability adapters remain later-checkpoint work.
 */
final class ToolInvocationBackgroundBinding
{
    public static function assertMatches(
        DeferredToolCompletionCorrelation $correlation,
        ToolInvocationBackgroundReadiness $readiness,
    ): void {
        if (!$correlation->matchesInvocationTuple(
            $readiness->runId,
            $readiness->turnNo,
            $readiness->stepId,
            $readiness->toolCallId,
        )) {
            throw new \RuntimeException('stale_identity');
        }

        if ($correlation->toolName !== $readiness->toolName) {
            throw new \RuntimeException('stale_identity');
        }

        if (null === $correlation->executionOwnerKind || null === $correlation->executionOwnerReference) {
            throw new \RuntimeException('not_ready');
        }

        if ($correlation->executionOwnerKind !== $readiness->ownerReference->kind
            || $correlation->executionOwnerReference->kind !== $readiness->ownerReference->kind
            || $correlation->executionOwnerReference->recordId !== $readiness->ownerReference->recordId) {
            throw new \RuntimeException('wrong_owner');
        }

        // Stored alias/controller/fingerprint are required exact matches; omission is not a wildcard.
        if (null !== $correlation->invocationId && $readiness->invocationId !== $correlation->invocationId) {
            throw new \RuntimeException('stale_identity');
        }

        if (null !== $correlation->controllerRunId && $readiness->controllerRunId !== $correlation->controllerRunId) {
            throw new \RuntimeException('stale_identity');
        }

        $expectedFingerprint = $correlation->envelopeFingerprint
            ?? ToolInvocationEnvelopeFingerprint::fromCorrelation($correlation);
        if ($readiness->envelopeFingerprint !== $expectedFingerprint) {
            throw new \RuntimeException('stale_identity');
        }
    }
}
