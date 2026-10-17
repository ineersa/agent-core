<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Tool;

use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationBackgroundBinding;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationBackgroundReadiness;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationEnvelopeFingerprint;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationOwnerReference;
use PHPUnit\Framework\TestCase;

final class ToolInvocationBackgroundBindingTest extends TestCase
{
    public function testExactBindingPasses(): void
    {
        $owner = new ToolInvocationOwnerReference('bash', 'record-1', 7);
        $correlation = $this->correlation($owner);
        ToolInvocationBackgroundBinding::assertMatches($correlation, $this->readiness($correlation, $owner));
        $this->addToAssertionCount(1);
    }

    public function testWrongOwnerFailsClosed(): void
    {
        $owner = new ToolInvocationOwnerReference('bash', 'record-1', 7);
        $correlation = $this->correlation($owner);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wrong_owner');
        ToolInvocationBackgroundBinding::assertMatches(
            $correlation,
            new ToolInvocationBackgroundReadiness(
                $correlation->runId,
                $correlation->turnNo,
                $correlation->stepId,
                $correlation->toolCallId,
                $correlation->toolName,
                new ToolInvocationOwnerReference('bash', 'other', 7),
                $correlation->invocationId,
                $correlation->controllerRunId,
                $correlation->envelopeFingerprint,
            ),
        );
    }

    public function testOmittedStoredAliasControllerOrFingerprintIsNotWildcard(): void
    {
        $owner = new ToolInvocationOwnerReference('bash', 'record-1', 7);
        $correlation = $this->correlation($owner);

        foreach ([
            new ToolInvocationBackgroundReadiness(
                $correlation->runId,
                $correlation->turnNo,
                $correlation->stepId,
                $correlation->toolCallId,
                $correlation->toolName,
                $owner,
                null,
                $correlation->controllerRunId,
                $correlation->envelopeFingerprint,
            ),
            new ToolInvocationBackgroundReadiness(
                $correlation->runId,
                $correlation->turnNo,
                $correlation->stepId,
                $correlation->toolCallId,
                $correlation->toolName,
                $owner,
                $correlation->invocationId,
                null,
                $correlation->envelopeFingerprint,
            ),
            new ToolInvocationBackgroundReadiness(
                $correlation->runId,
                $correlation->turnNo,
                $correlation->stepId,
                $correlation->toolCallId,
                $correlation->toolName,
                $owner,
                $correlation->invocationId,
                $correlation->controllerRunId,
                null,
            ),
        ] as $readiness) {
            try {
                ToolInvocationBackgroundBinding::assertMatches($correlation, $readiness);
                $this->fail('Omitted stored binding fields must not act as wildcards.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('stale_identity', $exception->getMessage());
            }
        }
    }

    private function correlation(ToolInvocationOwnerReference $owner): DeferredToolCompletionCorrelation
    {
        $base = new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440701',
            runId: 'run',
            turnNo: 1,
            stepId: 'tools',
            attempt: 1,
            idempotencyKey: 'key',
            toolCallId: 'call',
            toolName: 'bash',
            arguments: ['command' => 'sleep 1'],
            orderIndex: 0,
            invocationId: 'inv',
            controllerRunId: 'run',
            executionOwnerKind: 'bash',
            executionOwnerReference: $owner,
        );

        return new DeferredToolCompletionCorrelation(
            deferredId: $base->deferredId,
            runId: $base->runId,
            turnNo: $base->turnNo,
            stepId: $base->stepId,
            attempt: $base->attempt,
            idempotencyKey: $base->idempotencyKey,
            toolCallId: $base->toolCallId,
            toolName: $base->toolName,
            arguments: $base->arguments,
            orderIndex: $base->orderIndex,
            invocationId: $base->invocationId,
            controllerRunId: $base->controllerRunId,
            executionOwnerKind: $base->executionOwnerKind,
            executionOwnerReference: $base->executionOwnerReference,
            envelopeFingerprint: ToolInvocationEnvelopeFingerprint::fromCorrelation($base),
        );
    }

    private function readiness(
        DeferredToolCompletionCorrelation $correlation,
        ToolInvocationOwnerReference $owner,
    ): ToolInvocationBackgroundReadiness {
        return new ToolInvocationBackgroundReadiness(
            $correlation->runId,
            $correlation->turnNo,
            $correlation->stepId,
            $correlation->toolCallId,
            $correlation->toolName,
            $owner,
            $correlation->invocationId,
            $correlation->controllerRunId,
            $correlation->envelopeFingerprint,
        );
    }
}
