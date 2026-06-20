<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Orchestrator;

use Ineersa\AgentCore\Application\Pipeline\StartRunHandler;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\Builder\RunStateBuilder;
use Ineersa\AgentCore\Tests\Support\Builder\StartRunMessageBuilder;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\AgentCore\Tests\Support\TestSerializerFactory;
use PHPUnit\Framework\TestCase;

final class StartRunHandlerTest extends TestCase
{
    public function testHandleBuildsRunStartedTransitionWithoutReducer(): void
    {
        $handler = new StartRunHandler(
            eventFactory: new EventFactory(),
            normalizer: TestSerializerFactory::normalizer(),
        );

        $state = RunStateBuilder::create('run-start-handler-1')
            ->withStatus(RunStatus::Queued)
            ->withVersion(4)
            ->withTurnNo(3)
            ->withLastSeq(9)
            ->withIsStreaming(true)
            ->withStreamingMessage(['chunk' => 'old'])
            ->withPendingToolCalls(['legacy-call' => true])
            ->withErrorMessage('old error')
            ->withMessages([new AgentMessage(role: 'assistant', content: [])])
            ->withActiveStepId('legacy-step')
            ->withRetryableFailure(true)
            ->build();

        $message = StartRunMessageBuilder::create('run-start-handler-1')
            ->withIdempotencyKey('start-idempotency-1')
            ->withPayloadMessages([new AgentMessage(
                role: 'user',
                content: [[
                    'type' => 'text',
                    'text' => 'hello from start handler',
                ]],
            )])
            ->build();

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
        $commandBus = new TestMessageBus();

        $handler = new StartRunHandler(
            eventFactory: new EventFactory(),
            normalizer: TestSerializerFactory::normalizer(),
            commandBus: $commandBus,
        );

        $state = RunStateBuilder::queued('run-start-handler-2')->build();

        $message = StartRunMessageBuilder::create('run-start-handler-2')
            ->withStepId('start-step-2')
            ->withIdempotencyKey('start-idempotency-2')
            ->build();

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
        self::assertSame(hash('sha256', \sprintf('%s|%s', $advance->runId(), $advance->stepId())), $advance->idempotencyKey());
    }
}
