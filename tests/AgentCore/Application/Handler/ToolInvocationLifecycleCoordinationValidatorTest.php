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

final class ToolInvocationLifecycleCoordinationValidatorTest extends TestCase
{
    public function testIllegalTransitionIsRejectedBeforePlanAcceptance(): void
    {
        $validator = new ToolInvocationLifecycleCoordinationValidator();
        $action = new RecordToolInvocationLifecycleDTO(
            $this->correlation('call-illegal'),
            ToolInvocationCallerDispositionEnum::ForegroundResultAccepted,
            ToolInvocationExecutionObservationEnum::Active,
        );

        $this->expectException(\RuntimeException::class);
        $validator->validate($action);
    }

    public function testPlanRejectsDuplicateLifecycleUpdatesForOneInvocation(): void
    {
        $validator = new CoordinationActionValidator([new ToolInvocationLifecycleCoordinationValidator()]);
        $first = new RecordToolInvocationLifecycleDTO(
            $this->correlation('call-dup'),
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Active,
        );
        $second = new RecordToolInvocationLifecycleDTO(
            new DeferredToolCompletionCorrelation(
                deferredId: $first->expectedCorrelation->deferredId,
                runId: $first->expectedCorrelation->runId,
                turnNo: $first->expectedCorrelation->turnNo,
                stepId: $first->expectedCorrelation->stepId,
                attempt: $first->expectedCorrelation->attempt,
                idempotencyKey: $first->expectedCorrelation->idempotencyKey,
                toolCallId: $first->expectedCorrelation->toolCallId,
                toolName: $first->expectedCorrelation->toolName,
                arguments: $first->expectedCorrelation->arguments,
                orderIndex: $first->expectedCorrelation->orderIndex,
                callerDisposition: ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
                executionObservation: ToolInvocationExecutionObservationEnum::Active,
            ),
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::CancelRequested,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('only one lifecycle update');
        $validator->validatePlan([$first, $second]);
    }

    public function testPlanAllowsDistinctInvocationsAndExactTargetIdempotency(): void
    {
        $validator = new CoordinationActionValidator([new ToolInvocationLifecycleCoordinationValidator()]);
        $first = new RecordToolInvocationLifecycleDTO(
            $this->correlation('call-a'),
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Active,
        );
        $second = new RecordToolInvocationLifecycleDTO(
            $this->correlation('call-b'),
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Active,
        );
        $idempotent = new RecordToolInvocationLifecycleDTO(
            $this->correlation('call-c'),
            ToolInvocationCallerDispositionEnum::ForegroundPending,
            ToolInvocationExecutionObservationEnum::Active,
        );

        $validator->validatePlan([$first, $second, $idempotent]);
        $this->addToAssertionCount(1);
    }

    private function correlation(string $toolCallId): DeferredToolCompletionCorrelation
    {
        return new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440500',
            runId: 'run-lifecycle-validator',
            turnNo: 1,
            stepId: 'tools',
            attempt: 1,
            idempotencyKey: 'key-'.$toolCallId,
            toolCallId: $toolCallId,
            toolName: 'bash',
            arguments: ['command' => 'sleep 1'],
            orderIndex: 0,
        );
    }
}
