<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Orchestrator;

use Ineersa\AgentCore\Application\Orchestrator\RunMessageStateTools;
use Ineersa\AgentCore\Application\Orchestrator\StartRunHandler;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Message\StartRunPayload;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\TestSerializerFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class StartRunHandlerTest extends TestCase
{
    public function testHandleBuildsRunStartedTransitionWithoutReducer(): void
    {
        $handler = new StartRunHandler(
            stateTools: new RunMessageStateTools(),
            normalizer: TestSerializerFactory::normalizer(),
        );

        $state = new RunState(
            runId: 'run-start-handler-1',
            status: RunStatus::Queued,
            version: 4,
            turnNo: 3,
            lastSeq: 9,
            isStreaming: true,
            streamingMessage: ['chunk' => 'old'],
            pendingToolCalls: ['legacy-call' => true],
            errorMessage: 'old error',
            messages: [new AgentMessage(role: 'assistant', content: [])],
            activeStepId: 'legacy-step',
            retryableFailure: true,
        );

        $message = new StartRun(
            runId: 'run-start-handler-1',
            turnNo: 0,
            stepId: 'start-step-1',
            attempt: 1,
            idempotencyKey: 'start-idempotency-1',
            payload: new StartRunPayload(messages: [new AgentMessage(
                role: 'user',
                content: [[
                    'type' => 'text',
                    'text' => 'hello from start handler',
                ]],
            )]),
        );

        $result = $handler->handle($message, $state);

        self::assertNotNull($result->nextState);
        self::assertSame(RunStatus::Running, $result->nextState->status);
        self::assertSame(5, $result->nextState->version);
        self::assertSame(0, $result->nextState->turnNo);
        self::assertSame(10, $result->nextState->lastSeq);
        self::assertSame('start-step-1', $result->nextState->activeStepId);
        self::assertFalse($result->nextState->isStreaming);
        self::assertNull($result->nextState->streamingMessage);
        self::assertSame([], $result->nextState->pendingToolCalls);
        self::assertNull($result->nextState->errorMessage);
        self::assertFalse($result->nextState->retryableFailure);

        self::assertCount(1, $result->nextState->messages);
        self::assertSame('user', $result->nextState->messages[0]->role);

        self::assertCount(1, $result->events);
        self::assertSame('run_started', $result->events[0]->type);
        self::assertSame('start-step-1', $result->events[0]->payload['step_id']);

        self::assertSame([], $result->effects);
        self::assertSame([], $result->postCommitEffects);
        self::assertSame([], $result->postCommit);
        self::assertTrue($result->markHandled);
    }

    public function testHandleSchedulesInitialAdvanceAfterCommitWhenBusIsProvided(): void
    {
        $commandBus = new StartRunRecordingBus();

        $handler = new StartRunHandler(
            stateTools: new RunMessageStateTools(),
            normalizer: TestSerializerFactory::normalizer(),
            commandBus: $commandBus,
        );

        $state = new RunState(
            runId: 'run-start-handler-2',
            status: RunStatus::Queued,
            version: 0,
            turnNo: 0,
            lastSeq: 0,
            messages: [],
        );

        $message = new StartRun(
            runId: 'run-start-handler-2',
            turnNo: 0,
            stepId: 'start-step-2',
            attempt: 1,
            idempotencyKey: 'start-idempotency-2',
            payload: new StartRunPayload(messages: []),
        );

        $result = $handler->handle($message, $state);

        self::assertCount(1, $result->postCommit);
        ($result->postCommit[0])();

        self::assertCount(1, $commandBus->messages);
        self::assertInstanceOf(AdvanceRun::class, $commandBus->messages[0]);

        /** @var AdvanceRun $advance */
        $advance = $commandBus->messages[0];
        self::assertSame('run-start-handler-2', $advance->runId());
        self::assertSame(0, $advance->turnNo());
        self::assertStringStartsWith('start-follow-up-', $advance->stepId());
        self::assertSame(hash('sha256', sprintf('%s|%s', $advance->runId(), $advance->stepId())), $advance->idempotencyKey());
    }
}

final class StartRunRecordingBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;

        return new Envelope($message, $stamps);
    }
}
