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
use Ineersa\AgentCore\Contract\Tool\ActiveToolSet;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
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
            model: 'test-model');

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
            model: 'test-model');

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
            model: 'test-model');

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

    /**
     * Stop-boundary mailbox drain must carry CompactRun effects through
     * the HandlerResult so they are dispatched by RunCommit, not dropped.
     *
     * Without this fix, a queued compact during an active run is silently
     * discarded at the no-tool-call stop boundary.
     */
    public function testStopBoundaryMailboxEffectsContainPendingCompact(): void
    {
        $executionBus = new TestMessageBus();
        $stepDispatcher = new StepDispatcher($executionBus);

        $commandStore = new InMemoryCommandStore();

        // Pre-queue a compact command so the mailbox drains it
        $commandStore->enqueue(new PendingCommand(
            runId: 'run-stop-boundary-compact',
            kind: CoreCommandKind::Compact,
            idempotencyKey: 'compact-queued-ik',
            payload: ['custom_instructions' => 'Summarize.'],
        ));

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
            runId: 'run-stop-boundary-compact',
            status: RunStatus::Running,
            version: 3,
            turnNo: 1,
            lastSeq: 4,
            activeStepId: 'turn-1-step',
            model: 'test-model');

        // No-tool-call result triggers the stop-boundary mailbox drain
        $message = new LlmStepResult(
            runId: 'run-stop-boundary-compact',
            turnNo: 1,
            stepId: 'turn-1-step',
            attempt: 1,
            idempotencyKey: 'llm-no-tools-1',
            assistantMessage: SymfonyAiTestMessages::assistantText('All done.'),
            usage: ['total_tokens' => 5],
            stopReason: 'end_turn',
            error: null,
        );

        $result = $handler->handle($message, $state);

        $this->assertNotNull($result->nextState);

        // The mailbox drained a compact — should produce effects
        $this->assertNotEmpty($result->effects,
            'Stop-boundary mailbox drain must not drop CompactRun effects.',
        );

        // At least one effect should be a CompactRun
        $hasCompactRun = false;
        foreach ($result->effects as $effect) {
            if ($effect instanceof CompactRun) {
                $hasCompactRun = true;
                $this->assertSame('run-stop-boundary-compact', $effect->runId());
                $this->assertSame('manual', $effect->trigger);
                $this->assertSame('Summarize.', $effect->customInstructions);
                break;
            }
        }
        $this->assertTrue($hasCompactRun,
            'Stop-boundary effects must include the CompactRun dispatched from the mailbox drain.',
        );

        // Compact command should be marked applied in the store
        $this->assertCount(0, $commandStore->pending('run-stop-boundary-compact'),
            'Compact command must be drained (marked applied) from the store.',
        );
    }

    public function testErrorResultDoesNotAppendAssistantMessage(): void
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
            new \Ineersa\AgentCore\Domain\Message\AgentMessage(
                role: 'user',
                content: [['type' => 'text', 'text' => 'Hello']],
            ),
        ];

        $state = new RunState(
            runId: 'run-empty-asst',
            status: RunStatus::Running,
            version: 3,
            turnNo: 1,
            lastSeq: 4,
            activeStepId: 'turn-1-step',
            messages: $existingMessages,
            model: 'test-model');

        $message = new LlmStepResult(
            runId: 'run-empty-asst',
            turnNo: 1,
            stepId: 'turn-1-step',
            attempt: 1,
            idempotencyKey: 'empty-asst-1',
            assistantMessage: null,
            usage: ['input_tokens' => 100, 'output_tokens' => 50],
            stopReason: null,
            error: [
                'type' => 'empty_assistant_content',
                'message' => 'LLM provider returned reasoning without a final assistant response.',
                'retryable' => false,
            ],
        );

        $result = $handler->handle($message, $state);

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Failed, $result->nextState->status,
            'Thinking-only assistant must cause run failure, not success.',
        );

        // The error must NOT append any assistant message to state.
        $this->assertCount(
            \count($existingMessages),
            $result->nextState->messages,
            'Error result must not append assistant message to state messages.',
        );

        // The error details must propagate.
        $this->assertSame(
            'LLM provider returned reasoning without a final assistant response.',
            $result->nextState->errorMessage,
        );

        // The llm_step_failed event must carry the error.
        $llmStepFailed = null;
        foreach ($result->events as $event) {
            if ('llm_step_failed' === $event->type) {
                $llmStepFailed = $event;
            }
        }
        $this->assertNotNull($llmStepFailed, 'Must emit llm_step_failed event.');
        $this->assertSame(
            'empty_assistant_content',
            $llmStepFailed->payload['error']['type'] ?? null,
        );
        $this->assertFalse(
            $llmStepFailed->payload['retryable'] ?? true,
            'empty_assistant_content must be non-retryable.',
        );
    }

    public function testRetryableErrorBelowCapSchedulesAutomaticContinue(): void
    {
        $executionBus = new TestMessageBus();
        $commandBus = new TestMessageBus();
        $stepDispatcher = new StepDispatcher($executionBus);
        $classifier = new \Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmProviderErrorClassifier();

        $handler = new LlmStepResultHandler(
            toolBatchCollector: new ToolBatchCollector(),
            commandMailboxPolicy: new CommandMailboxPolicy(
                commandStore: new InMemoryCommandStore(),
                commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
            ),
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            stepDispatcher: $stepDispatcher,
            commandBus: $commandBus,
            errorClassifier: $classifier,
            agentRetryMaxAttempts: 2,
            agentRetryBaseDelayMs: 0,
            agentRetryMaxDelayMs: 0,
        );

        $state = new RunState(
            runId: 'run-auto-retry-1',
            status: RunStatus::Running,
            version: 2,
            turnNo: 1,
            lastSeq: 3,
            activeStepId: 'step-1',
            messages: [
                new \Ineersa\AgentCore\Domain\Message\AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Hi']]),
            ],
            retryAttempts: 0,
            model: 'test-model');

        $message = new LlmStepResult(
            runId: 'run-auto-retry-1',
            turnNo: 1,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: 'llm-retry-1',
            assistantMessage: null,
            usage: [],
            stopReason: null,
            error: [
                'type' => 'RuntimeException',
                'message' => 'Server Error',
                'http_status_code' => 503,
                'retryable' => true,
                'user_message' => 'LLM provider server error (HTTP 503 — retryable). Will retry automatically.',
            ],
        );

        $result = $handler->handle($message, $state);

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Failed, $result->nextState->status);
        $this->assertTrue($result->nextState->retryableFailure);
        $this->assertSame(1, $result->nextState->retryAttempts);

        $failed = null;
        foreach ($result->events as $event) {
            if ('llm_step_failed' === $event->type) {
                $failed = $event;
            }
        }
        $this->assertNotNull($failed);
        $this->assertSame(1, $failed->payload['retry_attempt'] ?? null);
        $this->assertSame(2, $failed->payload['max_retries'] ?? null);

        $this->assertNotEmpty($result->postCommit);
        foreach ($result->postCommit as $callback) {
            $callback();
        }

        $this->assertCount(1, $commandBus->messages);
        $this->assertInstanceOf(\Ineersa\AgentCore\Domain\Message\ApplyCommand::class, $commandBus->messages[0]);
        $this->assertSame(CoreCommandKind::Continue, $commandBus->messages[0]->kind);
        $this->assertTrue($commandBus->messages[0]->payload['auto_retry'] ?? false);
        $this->assertSame(1, $commandBus->messages[0]->payload['retry_attempt'] ?? null);
        $this->assertSame([], $commandBus->messages[0]->options);

        $routed = (new CommandRouter(new CommandHandlerRegistry([])))->route($commandBus->messages[0]);
        $this->assertSame('core', $routed->status, (string) $routed->reason);
    }

    public function testRetryableErrorAtCapDoesNotDispatchRetryAndStripsAutoRetryPromise(): void
    {
        $executionBus = new TestMessageBus();
        $commandBus = new TestMessageBus();
        $stepDispatcher = new StepDispatcher($executionBus);
        $classifier = new \Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmProviderErrorClassifier();

        $handler = new LlmStepResultHandler(
            toolBatchCollector: new ToolBatchCollector(),
            commandMailboxPolicy: new CommandMailboxPolicy(
                commandStore: new InMemoryCommandStore(),
                commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
            ),
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            stepDispatcher: $stepDispatcher,
            commandBus: $commandBus,
            errorClassifier: $classifier,
            agentRetryMaxAttempts: 2,
            agentRetryBaseDelayMs: 0,
            agentRetryMaxDelayMs: 0,
        );

        $state = new RunState(
            runId: 'run-exhausted',
            status: RunStatus::Running,
            version: 2,
            turnNo: 1,
            lastSeq: 3,
            activeStepId: 'step-1',
            messages: [
                new \Ineersa\AgentCore\Domain\Message\AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Hi']]),
            ],
            retryAttempts: 2,
            model: 'test-model');

        $message = new LlmStepResult(
            runId: 'run-exhausted',
            turnNo: 1,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: 'llm-exhausted-1',
            assistantMessage: null,
            usage: [],
            stopReason: null,
            error: [
                'type' => 'RuntimeException',
                'message' => 'Server Error',
                'http_status_code' => 503,
                'retryable' => true,
                'user_message' => 'LLM provider server error (HTTP 503 — retryable). Will retry automatically.',
            ],
        );

        $result = $handler->handle($message, $state);

        $this->assertNotNull($result->nextState);
        $this->assertFalse($result->nextState->retryableFailure);
        $this->assertStringNotContainsString('Will retry automatically', (string) $result->nextState->errorMessage);

        $failed = null;
        foreach ($result->events as $event) {
            if ('llm_step_failed' === $event->type) {
                $failed = $event;
            }
        }
        $this->assertNotNull($failed);
        $this->assertTrue($failed->payload['retries_exhausted'] ?? false);
        $this->assertFalse($failed->payload['retryable'] ?? true);
        $this->assertFalse($failed->payload['error']['retryable'] ?? true);

        foreach ($result->postCommit as $callback) {
            $callback();
        }
        $this->assertCount(0, $commandBus->messages);
    }

    public function testContextOverflow500DoesNotDispatchAutoRetryContinue(): void
    {
        $executionBus = new TestMessageBus();
        $commandBus = new TestMessageBus();
        $stepDispatcher = new StepDispatcher($executionBus);
        $classifier = new \Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmProviderErrorClassifier();

        $handler = new LlmStepResultHandler(
            toolBatchCollector: new ToolBatchCollector(),
            commandMailboxPolicy: new CommandMailboxPolicy(
                commandStore: new InMemoryCommandStore(),
                commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
            ),
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            stepDispatcher: $stepDispatcher,
            commandBus: $commandBus,
            errorClassifier: $classifier,
            agentRetryMaxAttempts: 2,
            agentRetryBaseDelayMs: 0,
            agentRetryMaxDelayMs: 0,
        );

        $error = $classifier->classify([
            'type' => 'RuntimeException',
            'message' => 'Context size has been exceeded.',
            'http_status_code' => 500,
        ]);

        $state = new RunState(
            runId: 'run-overflow',
            status: RunStatus::Running,
            version: 2,
            turnNo: 1,
            lastSeq: 3,
            activeStepId: 'step-1',
            messages: [
                new \Ineersa\AgentCore\Domain\Message\AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Hi']]),
            ],
            model: 'test-model');

        $message = new LlmStepResult(
            runId: 'run-overflow',
            turnNo: 1,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: 'llm-overflow-1',
            assistantMessage: null,
            usage: [],
            stopReason: null,
            error: $error,
        );

        $result = $handler->handle($message, $state);

        $this->assertFalse($result->nextState->retryableFailure);

        $hasCompact = false;
        foreach ($result->postCommit as $callback) {
            $callback();
        }
        foreach ($commandBus->messages as $dispatched) {
            if ($dispatched instanceof CompactRun) {
                $hasCompact = true;
            }
            $this->assertNotInstanceOf(\Ineersa\AgentCore\Domain\Message\ApplyCommand::class, $dispatched);
        }
        $this->assertTrue($hasCompact, 'Overflow recovery should dispatch CompactRun, not Continue.');
    }

    public function testParallelToolCallsCarryConfiguredMaxParallelism(): void
    {
        $executionBus = new TestMessageBus();
        $stepDispatcher = new StepDispatcher($executionBus);

        $toolSetResolver = new class implements ToolSetResolverInterface {
            public function resolve(string $toolsRef, ?int $turnNo = null, ?string $runId = null): ActiveToolSet
            {
                return new ActiveToolSet(
                    toolNames: ['bash'],
                    allowListNames: ['bash'],
                    executionModes: ['bash' => ToolExecutionMode::Parallel->value],
                );
            }
        };

        $handler = new LlmStepResultHandler(
            toolBatchCollector: new ToolBatchCollector(),
            commandMailboxPolicy: new CommandMailboxPolicy(
                commandStore: new InMemoryCommandStore(),
                commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
            ),
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            stepDispatcher: $stepDispatcher,
            toolSetResolver: $toolSetResolver,
            maxParallelism: 4,
        );

        $state = new RunState(
            runId: 'run-parallel-max',
            status: RunStatus::Running,
            version: 3,
            turnNo: 1,
            lastSeq: 4,
            activeStepId: 'turn-1-step',
            model: 'test-model');

        $message = new LlmStepResult(
            runId: 'run-parallel-max',
            turnNo: 1,
            stepId: 'turn-1-step',
            attempt: 1,
            idempotencyKey: 'llm-parallel-max',
            assistantMessage: SymfonyAiTestMessages::assistantWithToolCalls([
                ['id' => 'tool-call-a', 'name' => 'bash', 'arguments' => ['command' => 'sleep 1']],
                ['id' => 'tool-call-b', 'name' => 'bash', 'arguments' => ['command' => 'sleep 2']],
            ], 'parallel bash'),
            usage: [],
            stopReason: 'tool_call',
            error: null,
            toolsRef: 'default',
        );

        $result = $handler->handle($message, $state);
        ($result->postCommit[0])();

        $this->assertCount(2, $executionBus->messages);
        foreach ($executionBus->messages as $dispatched) {
            $this->assertInstanceOf(ExecuteToolCall::class, $dispatched);
            $this->assertSame(4, $dispatched->maxParallelism);
            $this->assertSame('parallel', $dispatched->mode);
        }
    }

    public function testExecuteToolCallHasNullTimeoutWithoutPerToolOverride(): void
    {
        $executionBus = new TestMessageBus();
        $stepDispatcher = new StepDispatcher($executionBus);
        $handler = new LlmStepResultHandler(
            toolBatchCollector: new ToolBatchCollector(),
            commandMailboxPolicy: new CommandMailboxPolicy(
                commandStore: new InMemoryCommandStore(),
                commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
            ),
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            stepDispatcher: $stepDispatcher,
        );

        $state = new RunState(
            runId: 'run-timeout-null',
            status: RunStatus::Running,
            version: 1,
            turnNo: 1,
            lastSeq: 1,
            activeStepId: 'step-1',
            model: 'test-model');

        $message = new LlmStepResult(
            runId: 'run-timeout-null',
            turnNo: 1,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: 'llm-timeout-null',
            assistantMessage: SymfonyAiTestMessages::assistantWithToolCalls([
                ['id' => 'tool-call-read', 'name' => 'read', 'arguments' => ['path' => 'README.md']],
            ], 'read file'),
            usage: [],
            stopReason: 'tool_call',
            error: null,
        );

        $result = $handler->handle($message, $state);
        $this->assertCount(1, $result->postCommit);
        ($result->postCommit[0])();

        $this->assertCount(1, $executionBus->messages);
        $execute = $executionBus->messages[0];
        $this->assertInstanceOf(ExecuteToolCall::class, $execute);
        $this->assertNull($execute->timeoutSeconds);
    }
}
