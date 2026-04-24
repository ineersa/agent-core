<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\CommandHandlerRegistry;
use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Orchestrator\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Orchestrator\LlmStepResultHandler;
use Ineersa\AgentCore\Application\Orchestrator\RunMessageStateTools;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Tests\Support\SymfonyAiTestMessages;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class LlmStepResultHandlerTest extends TestCase
{
    public function testHandleWithToolCallsReturnsPostCommitBatchRegistrationCallback(): void
    {
        $executionBus = new LlmHandlerRecordingBus();
        $stepDispatcher = new StepDispatcher($executionBus, new LlmHandlerRecordingBus());

        $commandStore = new InMemoryCommandStore();
        $handler = new LlmStepResultHandler(
            toolBatchCollector: new ToolBatchCollector(),
            commandMailboxPolicy: new CommandMailboxPolicy(
                commandStore: $commandStore,
                commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
            ),
            stateTools: new RunMessageStateTools(),
            stepDispatcher: $stepDispatcher,
        );

        $state = new RunState(
            runId: 'run-llm-handler-1',
            status: RunStatus::Running,
            version: 3,
            turnNo: 1,
            lastSeq: 4,
            activeStepId: 'turn-1-step',
        );

        $message = new LlmStepResult(
            runId: 'run-llm-handler-1',
            turnNo: 1,
            stepId: 'turn-1-step',
            attempt: 1,
            idempotencyKey: 'llm-idempotency-1',
            assistantMessage: SymfonyAiTestMessages::assistantWithToolCalls([
                [
                    'id' => 'tool-call-1',
                    'name' => 'search_docs',
                    'arguments' => ['query' => 'agent-core'],
                ],
            ], 'I will call a tool.'),
            usage: ['total_tokens' => 12],
            stopReason: 'tool_call',
            error: null,
        );

        $result = $handler->handle($message, $state);

        self::assertNotNull($result->nextState);
        self::assertSame(RunStatus::Running, $result->nextState->status);
        self::assertSame(4, $result->nextState->version);
        self::assertSame(6, $result->nextState->lastSeq);
        self::assertSame(['tool-call-1' => false], $result->nextState->pendingToolCalls);

        self::assertCount(2, $result->events);
        self::assertSame('llm_step_completed', $result->events[0]->type);
        self::assertSame('tool_execution_start', $result->events[1]->type);

        self::assertSame([], $result->effects);
        self::assertSame([], $result->postCommitEffects);
        self::assertCount(1, $result->postCommit);
        self::assertTrue($result->markHandled);

        ($result->postCommit[0])();

        self::assertCount(1, $executionBus->messages);
        self::assertInstanceOf(ExecuteToolCall::class, $executionBus->messages[0]);
        self::assertSame('tool-call-1', $executionBus->messages[0]->toolCallId);
    }
}

final class LlmHandlerRecordingBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;

        return new Envelope($message, $stamps);
    }
}
