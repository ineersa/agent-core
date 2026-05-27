<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\CommandHandlerRegistry;
use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Pipeline\LlmStepResultHandler;
use Ineersa\AgentCore\Application\Pipeline\RunMessageStateTools;
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
        $stepDispatcher = new StepDispatcher($executionBus);

        $commandStore = new InMemoryCommandStore();
        $handler = new LlmStepResultHandler(
            toolBatchCollector: new ToolBatchCollector(),
            commandMailboxPolicy: new CommandMailboxPolicy(
                commandStore: $commandStore,
                commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
            ),
            stateTools: new RunMessageStateTools(new \Ineersa\AgentCore\Domain\Event\EventFactory(), new \Ineersa\AgentCore\Application\Pipeline\ToolCallExtractor()),
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

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Running, $result->nextState->status);
        $this->assertSame(4, $result->nextState->version);
        $this->assertSame(6, $result->nextState->lastSeq);
        $this->assertSame(['tool-call-1' => false], $result->nextState->pendingToolCalls);

        $this->assertCount(2, $result->events);
        $this->assertSame('llm_step_completed', $result->events[0]->type);
        $this->assertSame('tool_execution_start', $result->events[1]->type);

        $this->assertSame([], $result->effects);
        $this->assertSame([], $result->postCommitEffects);
        $this->assertCount(1, $result->postCommit);
        $this->assertTrue($result->markHandled);

        ($result->postCommit[0])();

        $this->assertCount(1, $executionBus->messages);
        $this->assertInstanceOf(ExecuteToolCall::class, $executionBus->messages[0]);
        $this->assertSame('tool-call-1', $executionBus->messages[0]->toolCallId);
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
