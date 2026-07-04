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

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Running, $result->nextState->status);
        $this->assertSame(5, $result->nextState->version);
        $this->assertSame(0, $result->nextState->turnNo);
        $this->assertSame(10, $result->nextState->lastSeq);
        $this->assertSame('start-step-1', $result->nextState->activeStepId);
        $this->assertFalse($result->nextState->isStreaming);
        $this->assertNull($result->nextState->streamingMessage);
        $this->assertSame([], $result->nextState->pendingToolCalls);
        $this->assertNull($result->nextState->errorMessage);
        $this->assertFalse($result->nextState->retryableFailure);

        $this->assertCount(1, $result->nextState->messages);
        $this->assertSame('user', $result->nextState->messages[0]->role);

        $this->assertCount(1, $result->events);
        $this->assertSame('run_started', $result->events[0]->type);
        $this->assertSame('start-step-1', $result->events[0]->payload['step_id']);

        $this->assertSame([], $result->effects);
        $this->assertSame([], $result->postCommitEffects);
        $this->assertSame([], $result->postCommit);
        $this->assertTrue($result->markHandled);
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

        $this->assertCount(1, $result->postCommit);
        ($result->postCommit[0])();

        $this->assertCount(1, $commandBus->messages);
        $this->assertInstanceOf(AdvanceRun::class, $commandBus->messages[0]);

        /** @var AdvanceRun $advance */
        $advance = $commandBus->messages[0];
        $this->assertSame('run-start-handler-2', $advance->runId());
        $this->assertSame(0, $advance->turnNo());
        $this->assertStringStartsWith('start-follow-up-', $advance->stepId());
        $this->assertSame(hash('sha256', \sprintf('%s|%s', $advance->runId(), $advance->stepId())), $advance->idempotencyKey());
    }
}
