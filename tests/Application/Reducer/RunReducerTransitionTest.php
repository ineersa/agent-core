<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Reducer;

use Ineersa\AgentCore\Application\Reducer\RunReducer;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use PHPUnit\Framework\TestCase;

final class RunReducerTransitionTest extends TestCase
{
    public function testStartRunTransitionsQueuedStateToRunningAndHydratesMessages(): void
    {
        $reducer = new RunReducer();

        $result = $reducer->reduce(
            RunState::queued('run-reducer-1'),
            new StartRun(
                runId: 'run-reducer-1',
                turnNo: 0,
                stepId: 'start-1',
                attempt: 1,
                idempotencyKey: 'start-idemp-1',
                payload: [
                    'messages' => [[
                        'role' => 'user',
                        'content' => [[
                            'type' => 'text',
                            'text' => 'hello',
                        ]],
                    ]],
                ],
            ),
        );

        self::assertSame(RunStatus::Running, $result->state->status);
        self::assertSame(1, $result->state->version);
        self::assertSame(1, $result->state->lastSeq);
        self::assertSame('start-1', $result->state->activeStepId);
        self::assertCount(1, $result->state->messages);
        self::assertSame('user', $result->state->messages[0]->role);
        self::assertSame([], $result->effects);
    }

    public function testAdvanceRunEmitsLlmExecutionEffectForRunningState(): void
    {
        $reducer = new RunReducer();

        $state = new RunState(
            runId: 'run-reducer-2',
            status: RunStatus::Running,
            version: 3,
            turnNo: 2,
            lastSeq: 5,
            activeStepId: 'turn-2-llm-1',
        );

        $result = $reducer->reduce($state, new AdvanceRun(
            runId: 'run-reducer-2',
            turnNo: 2,
            stepId: 'turn-3-llm-1',
            attempt: 1,
            idempotencyKey: 'advance-idemp-1',
        ));

        self::assertSame(RunStatus::Running, $result->state->status);
        self::assertSame(4, $result->state->version);
        self::assertSame(3, $result->state->turnNo);
        self::assertSame(6, $result->state->lastSeq);
        self::assertSame('turn-3-llm-1', $result->state->activeStepId);

        self::assertCount(1, $result->effects);
        self::assertInstanceOf(ExecuteLlmStep::class, $result->effects[0]);
        self::assertSame('run-reducer-2', $result->effects[0]->runId());
        self::assertSame(3, $result->effects[0]->turnNo());
        self::assertSame('turn-3-llm-1', $result->effects[0]->stepId());
    }

    public function testAdvanceRunKeepsTerminalStateUnchanged(): void
    {
        $reducer = new RunReducer();

        $state = new RunState(
            runId: 'run-reducer-3',
            status: RunStatus::Completed,
            version: 7,
            turnNo: 4,
            lastSeq: 12,
            activeStepId: 'turn-4-end',
        );

        $result = $reducer->reduce($state, new AdvanceRun(
            runId: 'run-reducer-3',
            turnNo: 4,
            stepId: 'turn-5-llm-1',
            attempt: 1,
            idempotencyKey: 'advance-idemp-2',
        ));

        self::assertSame($state->status, $result->state->status);
        self::assertSame($state->version, $result->state->version);
        self::assertSame($state->turnNo, $result->state->turnNo);
        self::assertSame($state->lastSeq, $result->state->lastSeq);
        self::assertSame([], $result->effects);
    }

    public function testApplyCommandUpdatesCancelAndSteerStateTransitions(): void
    {
        $reducer = new RunReducer();

        $state = new RunState(
            runId: 'run-reducer-4',
            status: RunStatus::Running,
            version: 2,
            turnNo: 1,
            lastSeq: 3,
            activeStepId: 'turn-1-llm-1',
        );

        $cancelResult = $reducer->reduce($state, new ApplyCommand(
            runId: 'run-reducer-4',
            turnNo: 1,
            stepId: 'turn-1-llm-1',
            attempt: 1,
            idempotencyKey: 'cancel-idemp-1',
            kind: 'cancel',
            payload: ['reason' => 'operator cancel'],
        ));

        self::assertSame(RunStatus::Cancelling, $cancelResult->state->status);
        self::assertSame('operator cancel', $cancelResult->state->errorMessage);

        $steerResult = $reducer->reduce($state, new ApplyCommand(
            runId: 'run-reducer-4',
            turnNo: 1,
            stepId: 'turn-1-llm-1',
            attempt: 1,
            idempotencyKey: 'steer-idemp-1',
            kind: 'steer',
            payload: [
                'message' => [
                    'role' => 'user',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'steer it',
                    ]],
                ],
            ],
        ));

        self::assertCount(1, $steerResult->state->messages);
        self::assertSame('user', $steerResult->state->messages[0]->role);
        self::assertSame(3, $steerResult->state->version);
        self::assertSame(4, $steerResult->state->lastSeq);
    }
}
