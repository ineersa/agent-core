<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\CommandHandlerRegistry;
use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Pipeline\LlmStepResultHandler;
use Ineersa\AgentCore\Application\Pipeline\ToolCallExtractor;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Tests\Support\SymfonyAiTestMessages;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use PHPUnit\Framework\TestCase;

final class LlmStepResultHandlerTest extends TestCase
{
    public function testHandleWithToolCallsReturnsPostCommitBatchRegistrationCallback(): void
    {
        $executionBus = new TestMessageBus();
        $stepDispatcher = new StepDispatcher($executionBus);

        $commandStore = new InMemoryCommandStore();
        $handler = new LlmStepResultHandler(
            toolBatchCollector: new ToolBatchCollector(),
            commandMailboxPolicy: new CommandMailboxPolicy(
                commandStore: $commandStore,
                commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
            ),
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
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

    public function testAbortedDoesNotAppendAssistantMessageToState(): void
    {
        $executionBus = new TestMessageBus();
        $stepDispatcher = new StepDispatcher($executionBus);

        $commandStore = new InMemoryCommandStore();
        $handler = new LlmStepResultHandler(
            toolBatchCollector: new ToolBatchCollector(),
            commandMailboxPolicy: new CommandMailboxPolicy(
                commandStore: $commandStore,
                commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
            ),
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            stepDispatcher: $stepDispatcher,
        );

        $existingMessages = [
            new \Ineersa\AgentCore\Domain\Message\AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Hello']]),
        ];

        $state = new RunState(
            runId: 'run-abort-test',
            status: RunStatus::Running,
            version: 3,
            turnNo: 1,
            lastSeq: 4,
            activeStepId: 'abort-step',
            messages: $existingMessages,
        );

        $message = new LlmStepResult(
            runId: 'run-abort-test',
            turnNo: 1,
            stepId: 'abort-step',
            attempt: 1,
            idempotencyKey: 'llm-abort-1',
            assistantMessage: SymfonyAiTestMessages::assistantWithToolCalls([
                [
                    'id' => 'aborted-tool-1',
                    'name' => 'search_docs',
                    'arguments' => ['query' => 'something'],
                ],
            ], 'Partial output before abort'),
            usage: ['total_tokens' => 5],
            stopReason: 'aborted',
            error: null,
        );

        $result = $handler->handle($message, $state);

        // State must transition to Cancelled
        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Cancelled, $result->nextState->status);

        // The aborted assistant message must NOT be appended to messages
        $this->assertCount(
            \count($existingMessages),
            $result->nextState->messages,
            'Aborted assistant message must not be appended to state messages.',
        );
        $this->assertSame($existingMessages[0]->content, $result->nextState->messages[0]->content,
            'Existing messages must remain unchanged.',
        );

        // Events: llm_step_aborted + agent_end
        $this->assertCount(2, $result->events);
        $this->assertSame('llm_step_aborted', $result->events[0]->type);
        $this->assertSame('agent_end', $result->events[1]->type);

        // LlmStepAborted must carry sanitized assistant metadata
        $abortedPayload = $result->events[0]->payload['aborted_assistant'] ?? null;
        $this->assertNotNull($abortedPayload, 'LlmStepAborted must carry aborted_assistant metadata.');
        $this->assertTrue($abortedPayload['present']);
        $this->assertTrue($abortedPayload['has_tool_calls']);
        $this->assertSame(1, $abortedPayload['tool_call_count']);
        $this->assertSame(['aborted-tool-1'], $abortedPayload['tool_call_ids']);
        $this->assertSame(\strlen('Partial output before abort'), $abortedPayload['text_length'], 'Text length should match.');
        $this->assertNotNull($abortedPayload['text_sha256']);
        $this->assertFalse($abortedPayload['has_thinking']);
    }

    public function testAbortedWithOnlyTextDoesNotAppendMessage(): void
    {
        $executionBus = new TestMessageBus();
        $stepDispatcher = new StepDispatcher($executionBus);

        $commandStore = new InMemoryCommandStore();
        $handler = new LlmStepResultHandler(
            toolBatchCollector: new ToolBatchCollector(),
            commandMailboxPolicy: new CommandMailboxPolicy(
                commandStore: $commandStore,
                commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
            ),
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            stepDispatcher: $stepDispatcher,
        );

        $existingMessages = [
            new \Ineersa\AgentCore\Domain\Message\AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Do something']]),
        ];

        $state = new RunState(
            runId: 'run-abort-text',
            status: RunStatus::Running,
            version: 2,
            turnNo: 1,
            lastSeq: 3,
            activeStepId: 'abort-text-step',
            messages: $existingMessages,
        );

        $message = new LlmStepResult(
            runId: 'run-abort-text',
            turnNo: 1,
            stepId: 'abort-text-step',
            attempt: 1,
            idempotencyKey: 'llm-abort-text-1',
            assistantMessage: SymfonyAiTestMessages::assistantText('Partial assistant output before cancellation'),
            usage: [],
            stopReason: 'aborted',
            error: null,
        );

        $result = $handler->handle($message, $state);

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Cancelled, $result->nextState->status);

        // No assistant message appended (even text-only aborted output)
        $this->assertCount(
            \count($existingMessages),
            $result->nextState->messages,
            'Aborted text-only assistant must not be appended to messages.',
        );

        $this->assertCount(2, $result->events);
        $this->assertSame('llm_step_aborted', $result->events[0]->type);

        $abortedPayload = $result->events[0]->payload['aborted_assistant'] ?? null;
        $this->assertNotNull($abortedPayload);
        $this->assertTrue($abortedPayload['present']);
        $this->assertFalse($abortedPayload['has_tool_calls']);
        $this->assertSame(0, $abortedPayload['tool_call_count']);
        $this->assertSame([], $abortedPayload['tool_call_ids']);
        $this->assertNotNull($abortedPayload['text_sha256']);
    }
}


