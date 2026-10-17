<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Tool;

use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationCallerDispositionEnum;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationEnvelopeFingerprint;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationExecutionObservationEnum;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationLifecycleTransition;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationOwnerReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToolInvocationLifecycleTransitionTest extends TestCase
{
    #[DataProvider('allowedPrincipalTransitions')]
    public function testAllowedPrincipalTransitions(
        ToolInvocationCallerDispositionEnum $fromDisposition,
        ToolInvocationExecutionObservationEnum $fromObservation,
        ToolInvocationCallerDispositionEnum $toDisposition,
        ToolInvocationExecutionObservationEnum $toObservation,
    ): void {
        $this->expectNotToPerformAssertions();
        ToolInvocationLifecycleTransition::assertAllowed(
            $fromDisposition,
            $fromObservation,
            $toDisposition,
            $toObservation,
        );
    }

    /**
     * @return iterable<string, array{
     *     ToolInvocationCallerDispositionEnum,
     *     ToolInvocationExecutionObservationEnum,
     *     ToolInvocationCallerDispositionEnum,
     *     ToolInvocationExecutionObservationEnum
     * }>
     */
    public static function allowedPrincipalTransitions(): iterable
    {
        yield 'foreground_pending/active no-op' => [
            ToolInvocationCallerDispositionEnum::ForegroundPending,
            ToolInvocationExecutionObservationEnum::Active,
            ToolInvocationCallerDispositionEnum::ForegroundPending,
            ToolInvocationExecutionObservationEnum::Active,
        ];
        yield 'foreground_pending/active to foreground_result_accepted/terminal' => [
            ToolInvocationCallerDispositionEnum::ForegroundPending,
            ToolInvocationExecutionObservationEnum::Active,
            ToolInvocationCallerDispositionEnum::ForegroundResultAccepted,
            ToolInvocationExecutionObservationEnum::Terminal,
        ];
        yield 'foreground_pending/active to background_acknowledged/active' => [
            ToolInvocationCallerDispositionEnum::ForegroundPending,
            ToolInvocationExecutionObservationEnum::Active,
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Active,
        ];
        yield 'background_acknowledged/active to cancel_requested' => [
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Active,
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::CancelRequested,
        ];
        yield 'background_acknowledged/active to terminal' => [
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Active,
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Terminal,
        ];
        yield 'background_acknowledged/cancel_requested to terminal' => [
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::CancelRequested,
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Terminal,
        ];
        yield 'background_acknowledged/cancel_requested to unknown' => [
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::CancelRequested,
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Unknown,
        ];
    }

    #[DataProvider('refusedPrincipalTransitions')]
    public function testRefusedPrincipalTransitions(
        ToolInvocationCallerDispositionEnum $fromDisposition,
        ToolInvocationExecutionObservationEnum $fromObservation,
        ToolInvocationCallerDispositionEnum $toDisposition,
        ToolInvocationExecutionObservationEnum $toObservation,
    ): void {
        $this->expectException(\RuntimeException::class);
        ToolInvocationLifecycleTransition::assertAllowed(
            $fromDisposition,
            $fromObservation,
            $toDisposition,
            $toObservation,
        );
    }

    /**
     * @return iterable<string, array{
     *     ToolInvocationCallerDispositionEnum,
     *     ToolInvocationExecutionObservationEnum,
     *     ToolInvocationCallerDispositionEnum,
     *     ToolInvocationExecutionObservationEnum
     * }>
     */
    public static function refusedPrincipalTransitions(): iterable
    {
        yield 'foreground_pending/active to foreground_result_accepted/active' => [
            ToolInvocationCallerDispositionEnum::ForegroundPending,
            ToolInvocationExecutionObservationEnum::Active,
            ToolInvocationCallerDispositionEnum::ForegroundResultAccepted,
            ToolInvocationExecutionObservationEnum::Active,
        ];
        yield 'foreground_pending/active to unknown' => [
            ToolInvocationCallerDispositionEnum::ForegroundPending,
            ToolInvocationExecutionObservationEnum::Active,
            ToolInvocationCallerDispositionEnum::ForegroundPending,
            ToolInvocationExecutionObservationEnum::Unknown,
        ];
        yield 'background_acknowledged/active to unknown' => [
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Active,
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Unknown,
        ];
        yield 'foreground_result_accepted/terminal cannot leave' => [
            ToolInvocationCallerDispositionEnum::ForegroundResultAccepted,
            ToolInvocationExecutionObservationEnum::Terminal,
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Active,
        ];
        yield 'background_acknowledged/terminal cannot leave' => [
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Terminal,
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::CancelRequested,
        ];
        yield 'background_acknowledged/unknown cannot leave' => [
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Unknown,
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Terminal,
        ];
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

    public function testOwnerReferenceRequiresMatchingKindAndFingerprintMustMatchEnvelope(): void
    {
        try {
            new DeferredToolCompletionCorrelation(
                deferredId: '550e8400-e29b-41d4-a716-446655440302',
                runId: 'run-1',
                turnNo: 2,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-1',
                toolCallId: 'call-owner',
                toolName: 'bash',
                arguments: ['command' => 'sleep 1'],
                orderIndex: 0,
                executionOwnerReference: new ToolInvocationOwnerReference('bash', 'record-9'),
            );
            $this->fail('Owner reference without kind must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('requires an owner kind', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440303',
            runId: 'run-1',
            turnNo: 2,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: 'key-1',
            toolCallId: 'call-fingerprint',
            toolName: 'bash',
            arguments: ['command' => 'sleep 1'],
            orderIndex: 0,
            envelopeFingerprint: ToolInvocationEnvelopeFingerprint::compute(
                'run-1',
                2,
                'step-1',
                'call-fingerprint',
                'bash',
                'key-1',
                ['command' => 'sleep 2'],
            ),
        );
    }
}
