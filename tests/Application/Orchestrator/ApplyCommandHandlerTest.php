<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\CommandHandlerRegistry;
use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Orchestrator\ApplyCommandHandler;
use Ineersa\AgentCore\Application\Orchestrator\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Orchestrator\RunMessageStateTools;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ApplyCommandHandlerTest extends TestCase
{
    public function testContinueCommandProducesPostCommitFollowUpAdvance(): void
    {
        $commandStore = new InMemoryCommandStore();
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
        );

        $commandBus = new ApplyCommandRecordingBus();

        $handler = new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            commandMailboxPolicy: $commandMailboxPolicy,
            stateTools: new RunMessageStateTools(),
            maxPendingCommands: 10,
            commandBus: $commandBus,
        );

        $state = new RunState(
            runId: 'run-apply-handler-1',
            status: RunStatus::Failed,
            version: 2,
            turnNo: 4,
            lastSeq: 5,
            messages: [new AgentMessage(role: 'tool', content: [])],
            retryableFailure: true,
        );

        $message = new ApplyCommand(
            runId: 'run-apply-handler-1',
            turnNo: 4,
            stepId: 'continue-step-1',
            attempt: 1,
            idempotencyKey: 'continue-idempotency-1',
            kind: CoreCommandKind::Continue,
            payload: [],
        );

        $result = $handler->handle($message, $state);

        self::assertNotNull($result->nextState);
        self::assertSame(RunStatus::Running, $result->nextState->status);
        self::assertSame(3, $result->nextState->version);
        self::assertSame(6, $result->nextState->lastSeq);
        self::assertNull($result->nextState->errorMessage);
        self::assertFalse($result->nextState->retryableFailure);

        self::assertCount(1, $result->events);
        self::assertSame('agent_command_applied', $result->events[0]->type);
        self::assertSame(CoreCommandKind::Continue, $result->events[0]->payload['kind']);

        self::assertSame([], $result->effects);
        self::assertSame([], $result->postCommitEffects);
        self::assertCount(1, $result->postCommit);
        self::assertTrue($result->markHandled);

        ($result->postCommit[0])();

        self::assertCount(1, $commandBus->messages);
        self::assertInstanceOf(AdvanceRun::class, $commandBus->messages[0]);
        self::assertTrue($commandStore->has('run-apply-handler-1', 'continue-idempotency-1'));
    }
}

final class ApplyCommandRecordingBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;

        return new Envelope($message, $stamps);
    }
}
