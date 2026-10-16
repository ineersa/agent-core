<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\CoordinationActionValidator;
use Ineersa\AgentCore\Application\Handler\ToolInvocationLifecycleCoordinationValidator;
use Ineersa\AgentCore\Domain\Coordination\RecordToolInvocationLifecycleDTO;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationCallerDispositionEnum;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationExecutionObservationEnum;
use PHPUnit\Framework\TestCase;

final class CoordinationActionValidatorTest extends TestCase
{
    public function testPlanRejectsDuplicateStoreIdentityEvenWithDifferentTurnStepOrAliasMetadata(): void
    {
        $validator = $this->validator();
        $first = new RecordToolInvocationLifecycleDTO(
            $this->correlation(
                toolCallId: 'call-same',
                turnNo: 1,
                stepId: 'tools-a',
                deferredId: '550e8400-e29b-41d4-a716-446655440601',
                idempotencyKey: 'key-a',
            ),
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Active,
        );
        $second = new RecordToolInvocationLifecycleDTO(
            $this->correlation(
                toolCallId: 'call-same',
                turnNo: 2,
                stepId: 'tools-b',
                deferredId: '550e8400-e29b-41d4-a716-446655440602',
                idempotencyKey: 'key-b',
                callerDisposition: ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
                executionObservation: ToolInvocationExecutionObservationEnum::Active,
            ),
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::CancelRequested,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('only one lifecycle update for run "run-lifecycle-plan" tool call "call-same"');
        $validator->validatePlan([$first, $second]);
    }

    public function testPlanRejectsDuplicateIdenticalLifecycleActions(): void
    {
        $validator = $this->validator();
        $action = new RecordToolInvocationLifecycleDTO(
            $this->correlation(toolCallId: 'call-identical'),
            ToolInvocationCallerDispositionEnum::ForegroundPending,
            ToolInvocationExecutionObservationEnum::Active,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('only one lifecycle update');
        $validator->validatePlan([$action, $action]);
    }

    public function testPlanAllowsDistinctStoreIdentitiesAndExactTargetNoOpAction(): void
    {
        $validator = $this->validator();
        $first = new RecordToolInvocationLifecycleDTO(
            $this->correlation(toolCallId: 'call-a'),
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Active,
        );
        $second = new RecordToolInvocationLifecycleDTO(
            $this->correlation(toolCallId: 'call-b'),
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Active,
        );
        $exactTargetNoOp = new RecordToolInvocationLifecycleDTO(
            $this->correlation(toolCallId: 'call-c'),
            ToolInvocationCallerDispositionEnum::ForegroundPending,
            ToolInvocationExecutionObservationEnum::Active,
        );

        $validator->validatePlan([$first, $second, $exactTargetNoOp]);
        $this->addToAssertionCount(1);
    }

    private function validator(): CoordinationActionValidator
    {
        return new CoordinationActionValidator([new ToolInvocationLifecycleCoordinationValidator()]);
    }

    private function correlation(
        string $toolCallId,
        int $turnNo = 1,
        string $stepId = 'tools',
        string $deferredId = '550e8400-e29b-41d4-a716-446655440600',
        string $idempotencyKey = 'key',
        ToolInvocationCallerDispositionEnum $callerDisposition = ToolInvocationCallerDispositionEnum::ForegroundPending,
        ToolInvocationExecutionObservationEnum $executionObservation = ToolInvocationExecutionObservationEnum::Active,
    ): DeferredToolCompletionCorrelation {
        return new DeferredToolCompletionCorrelation(
            deferredId: $deferredId,
            runId: 'run-lifecycle-plan',
            turnNo: $turnNo,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: $idempotencyKey,
            toolCallId: $toolCallId,
            toolName: 'bash',
            arguments: ['command' => 'sleep 1'],
            orderIndex: 0,
            callerDisposition: $callerDisposition,
            executionObservation: $executionObservation,
        );
    }
}
