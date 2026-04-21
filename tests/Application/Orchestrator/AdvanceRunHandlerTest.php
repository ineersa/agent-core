<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\CommandHandlerRegistry;
use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Handler\RunMetrics;
use Ineersa\AgentCore\Application\Orchestrator\AdvanceRunHandler;
use Ineersa\AgentCore\Application\Orchestrator\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Orchestrator\RunMessageStateTools;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use PHPUnit\Framework\TestCase;

final class AdvanceRunHandlerTest extends TestCase
{
    public function testHandleProducesTurnAdvanceEventAndLlmExecutionEffect(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
        );
        $metrics = new RunMetrics();

        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: $commandMailboxPolicy,
            stateTools: new RunMessageStateTools(),
            metrics: $metrics,
        );

        $state = new RunState(
            runId: 'run-advance-handler-1',
            status: RunStatus::Running,
            version: 7,
            turnNo: 2,
            lastSeq: 11,
            activeStepId: 'turn-2-step',
        );

        $message = new AdvanceRun(
            runId: 'run-advance-handler-1',
            turnNo: 2,
            stepId: 'turn-3-step',
            attempt: 1,
            idempotencyKey: 'advance-idempotency-1',
        );

        $result = $handler->handle($message, $state);

        self::assertNotNull($result->nextState);
        self::assertSame(RunStatus::Running, $result->nextState->status);
        self::assertSame(8, $result->nextState->version);
        self::assertSame(3, $result->nextState->turnNo);
        self::assertSame(12, $result->nextState->lastSeq);
        self::assertSame('turn-3-step', $result->nextState->activeStepId);

        self::assertCount(1, $result->events);
        self::assertSame('turn_advanced', $result->events[0]->type);

        self::assertCount(1, $result->effects);
        self::assertInstanceOf(ExecuteLlmStep::class, $result->effects[0]);
        self::assertSame(3, $result->effects[0]->turnNo());
        self::assertSame('turn-3-step', $result->effects[0]->stepId());

        self::assertSame([], $result->postCommitEffects);
        self::assertCount(1, $result->postCommit);
        self::assertTrue($result->markHandled);

        ($result->postCommit[0])();

        self::assertIsArray($metrics->snapshot());
    }
}
