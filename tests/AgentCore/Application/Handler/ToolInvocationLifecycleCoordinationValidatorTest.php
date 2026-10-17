<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

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
            new DeferredToolCompletionCorrelation(
                deferredId: '550e8400-e29b-41d4-a716-446655440500',
                runId: 'run-lifecycle-validator',
                turnNo: 1,
                stepId: 'tools',
                attempt: 1,
                idempotencyKey: 'key-illegal',
                toolCallId: 'call-illegal',
                toolName: 'bash',
                arguments: ['command' => 'sleep 1'],
                orderIndex: 0,
            ),
            ToolInvocationCallerDispositionEnum::ForegroundResultAccepted,
            ToolInvocationExecutionObservationEnum::Active,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported tool invocation lifecycle transition');
        $validator->validate($action);
    }

    public function testExactTargetNoOpIsAllowedPerAction(): void
    {
        $validator = new ToolInvocationLifecycleCoordinationValidator();
        $validator->validate(new RecordToolInvocationLifecycleDTO(
            new DeferredToolCompletionCorrelation(
                deferredId: '550e8400-e29b-41d4-a716-446655440501',
                runId: 'run-lifecycle-validator',
                turnNo: 1,
                stepId: 'tools',
                attempt: 1,
                idempotencyKey: 'key-noop',
                toolCallId: 'call-noop',
                toolName: 'bash',
                arguments: ['command' => 'sleep 1'],
                orderIndex: 0,
            ),
            ToolInvocationCallerDispositionEnum::ForegroundPending,
            ToolInvocationExecutionObservationEnum::Active,
        ));

        $this->addToAssertionCount(1);
    }
}
