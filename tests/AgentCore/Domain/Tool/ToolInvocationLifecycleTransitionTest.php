<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Tool;

use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationCallerDispositionEnum;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationEnvelopeFingerprint;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationExecutionObservationEnum;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationLifecycleTransition;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationOwnerReference;
use PHPUnit\Framework\TestCase;

final class ToolInvocationLifecycleTransitionTest extends TestCase
{
    public function testPrincipalTransitionsAndEnvelopeMismatchRefusal(): void
    {
        $base = new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440300',
            runId: 'run-1',
            turnNo: 2,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: 'key-1',
            toolCallId: 'call-1',
            toolName: 'bash',
            arguments: ['command' => 'sleep 1'],
            orderIndex: 0,
            executionOwnerKind: 'bash',
            executionOwnerReference: new ToolInvocationOwnerReference('bash', 'record-9', 77, '/tmp/a.log'),
            envelopeFingerprint: ToolInvocationEnvelopeFingerprint::compute(
                'run-1',
                2,
                'step-1',
                'call-1',
                'bash',
                'key-1',
                ['command' => 'sleep 1'],
            ),
        );

        $acknowledged = $base->withLifecycle(
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Active,
        );
        $this->assertSame(ToolInvocationCallerDispositionEnum::BackgroundAcknowledged, $acknowledged->callerDisposition);
        $this->assertSame(ToolInvocationExecutionObservationEnum::Active, $acknowledged->executionObservation);

        $cancelled = $acknowledged->withLifecycle(
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::CancelRequested,
        );
        $this->assertSame(ToolInvocationExecutionObservationEnum::CancelRequested, $cancelled->executionObservation);

        $this->expectException(\RuntimeException::class);
        ToolInvocationLifecycleTransition::assertAllowed(
            ToolInvocationCallerDispositionEnum::ForegroundPending,
            ToolInvocationExecutionObservationEnum::Active,
            ToolInvocationCallerDispositionEnum::ForegroundResultAccepted,
            ToolInvocationExecutionObservationEnum::Active,
        );
    }

    public function testEnvelopeFingerprintMismatchIsRejected(): void
    {
        $left = new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440301',
            runId: 'run-1',
            turnNo: 2,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: 'key-1',
            toolCallId: 'call-1',
            toolName: 'bash',
            arguments: ['command' => 'sleep 1'],
            orderIndex: 0,
        );
        $right = new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440301',
            runId: 'run-1',
            turnNo: 2,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: 'key-1',
            toolCallId: 'call-1',
            toolName: 'bash',
            arguments: ['command' => 'sleep 2'],
            orderIndex: 0,
        );

        $this->expectException(\RuntimeException::class);
        $left->assertEnvelopeMatches($right);
    }
}
