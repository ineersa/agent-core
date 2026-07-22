<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ExecuteToolCallWorker;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Application\Pipeline\ToolCallExtractor;
use Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\HumanInputContinuationKindEnum;
use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionHumanInputSuspension;
use Ineersa\AgentCore\Tests\Support\Builder\RunStateBuilder;
use Ineersa\AgentCore\Tests\Support\InMemoryDeferredToolCompletionRepository;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult as SymfonyToolResult;
use Symfony\AI\Platform\Result\ToolCall as SymfonyToolCall;

/** Slice B: non-terminal tool-call human-input via existing ToolCallResult. */
final class ToolCallHumanInputSuspensionTest extends TestCase
{
    public function testWorkerDispatchesNonTerminalToolCallResultWithoutRemembering(): void
    {
        $toolbox = new class implements ToolboxInterface {
            public function getTools(): array
            {
                return [];
            }

            public function execute(SymfonyToolCall $toolCall): SymfonyToolResult
            {
                return new SymfonyToolResult($toolCall, new ToolExecutionHumanInputSuspension(
                    PendingHumanInputRequestDTO::toolCallFromPayload(
                        ['question_id' => 'req-1', 'prompt' => 'Allow?'],
                        ['run_id' => 'run-susp', 'turn_no' => 2, 'step_id' => 'turn-2-tools-1', 'tool_call_id' => $toolCall->getId()],
                    ),
                ));
            }
        };
        $store = new ToolExecutionResultStore();
        $bus = new TestMessageBus();
        (new ExecuteToolCallWorker(
            new ToolExecutor('parallel', 30, 2, $store, toolbox: $toolbox),
            $bus,
            new InMemoryDeferredToolCompletionRepository(),
        ))(new ExecuteToolCall('run-susp', 2, 'turn-2-tools-1', 1, 'idemp', 'call-susp', 'bash', ['command' => 'env'], 0));

        $this->assertInstanceOf(ToolCallResult::class, $bus->messages[0] ?? null);
        /** @var ToolCallResult $envelope */
        $envelope = $bus->messages[0];
        $this->assertNotNull($envelope->pendingHumanInput);
        $this->assertSame(HumanInputContinuationKindEnum::ToolCall, $envelope->pendingHumanInput->continuationKind);
        $this->assertNull($store->findByRunToolCall('run-susp', 'call-susp'));
    }

    public function testBatchAdmissionIsIdempotentAndFreesDispatchCapacity(): void
    {
        $collector = new ToolBatchCollector(defaultMaxParallelism: 1);
        $collector->registerExpectedBatch('run-b', 1, 'step-b', [
            $this->call('run-b', 'step-b', 'call-1', 0),
            $this->call('run-b', 'step-b', 'call-2', 1),
        ]);
        $this->assertSame('call-2', $collector->admitHumanInputSuspension('run-b', 1, 'step-b', 'call-1', 'q-1')[0]->toolCallId);
        $this->assertSame([], $collector->admitHumanInputSuspension('run-b', 1, 'step-b', 'call-1', 'q-1'));
        $this->expectException(\LogicException::class);
        $collector->admitHumanInputSuspension('run-b', 1, 'step-b', 'call-1', 'q-other');
    }

    public function testHandlerAdmitsWaitingHumanAndReplayReconstructsToolCallRequest(): void
    {
        $collector = new ToolBatchCollector();
        $collector->registerExpectedBatch('run-h', 3, 'step-h', [$this->call('run-h', 'step-h', 'call-h', 0, 3)]);
        $request = PendingHumanInputRequestDTO::toolCallFromPayload(
            ['question_id' => 'q-h', 'prompt' => 'Allow id?'],
            ['run_id' => 'run-h', 'turn_no' => 3, 'step_id' => 'step-h', 'tool_call_id' => 'call-h'],
        );
        $result = (new ToolCallResultHandler($collector, new EventFactory(), new ToolCallExtractor(), new AgentMessageNormalizer()))->handle(
            ToolCallResultFactory::fromExecuteToolCallAndHumanInputSuspension(
                $this->call('run-h', 'step-h', 'call-h', 0, 3),
                new ToolExecutionHumanInputSuspension($request),
            ),
            RunStateBuilder::running('run-h')->withTurnNo(3)->withLastSeq(5)->withActiveStepId('step-h')->withPendingToolCalls(['call-h' => false])->build(),
        );

        $this->assertSame(RunStatus::WaitingHuman, $result->nextState?->status);
        $this->assertSame(['call-h' => false], $result->nextState?->pendingToolCalls);
        $this->assertSame([], $result->nextState?->messages);
        $this->assertSame(HumanInputContinuationKindEnum::ToolCall, $result->nextState->pendingHumanInputRequests[0]->continuationKind);
        $this->assertArrayNotHasKey('continuation_kind', $result->nextState->pendingHumanInputRequests[0]->payload);
        $this->assertArrayNotHasKey('continuation_ref', $result->nextState->pendingHumanInputRequests[0]->payload);

        $payload = null;
        foreach ($result->events as $event) {
            if (RunEventTypeEnum::WaitingHuman->value === $event->type) {
                $payload = $event->payload;
            }
        }
        $this->assertSame('tool_call', $payload['continuation_kind'] ?? null);
        $this->assertSame('call-h', $payload['continuation_ref']['tool_call_id'] ?? null);
        $replayed = (new RunStateReducer())->replay(RunState::queued('run-h'), [new RunEvent('run-h', 1, 3, RunEventTypeEnum::WaitingHuman->value, $payload ?? [])]);
        $this->assertSame(HumanInputContinuationKindEnum::ToolCall, $replayed->pendingHumanInputRequests[0]->continuationKind);
        $this->assertSame('q-h', $replayed->pendingHumanInputRequests[0]->questionId);
    }

    public function testOrdinarySiblingResultWhileSuspendedPreservesWaitingHumanWithoutBatchCommit(): void
    {
        $collector = new ToolBatchCollector(defaultMaxParallelism: 2);
        $collector->registerExpectedBatch('run-par', 1, 'step-par', [
            $this->call('run-par', 'step-par', 'call-1', 0, mode: 'parallel', maxParallelism: 2),
            $this->call('run-par', 'step-par', 'call-2', 1, mode: 'parallel', maxParallelism: 2),
        ]);

        $handler = new ToolCallResultHandler($collector, new EventFactory(), new ToolCallExtractor(), new AgentMessageNormalizer());
        $state = RunStateBuilder::running('run-par')
            ->withTurnNo(1)
            ->withLastSeq(3)
            ->withActiveStepId('step-par')
            ->withPendingToolCalls(['call-1' => false, 'call-2' => false])
            ->build();

        $suspend = $handler->handle(
            ToolCallResultFactory::fromExecuteToolCallAndHumanInputSuspension(
                $this->call('run-par', 'step-par', 'call-1', 0, mode: 'parallel', maxParallelism: 2),
                new ToolExecutionHumanInputSuspension(PendingHumanInputRequestDTO::toolCallFromPayload(
                    ['question_id' => 'q-shared', 'prompt' => 'Allow call-1?'],
                    ['run_id' => 'run-par', 'turn_no' => 1, 'step_id' => 'step-par', 'tool_call_id' => 'call-1'],
                )),
            ),
            $state,
        );
        $this->assertSame(RunStatus::WaitingHuman, $suspend->nextState?->status);
        $this->assertSame(['call-1' => false, 'call-2' => false], $suspend->nextState?->pendingToolCalls);

        $sibling = $handler->handle(
            new ToolCallResult(
                runId: 'run-par',
                turnNo: 1,
                stepId: 'step-par',
                attempt: 1,
                idempotencyKey: 'res-call-2',
                toolCallId: 'call-2',
                orderIndex: 1,
                result: ['tool_name' => 'bash', 'content' => [['type' => 'text', 'text' => 'ok-2']]],
            ),
            $suspend->nextState,
        );

        $this->assertSame(RunStatus::WaitingHuman, $sibling->nextState?->status);
        $this->assertSame(['call-1' => false, 'call-2' => true], $sibling->nextState?->pendingToolCalls);
        $this->assertCount(1, $sibling->nextState?->pendingHumanInputRequests ?? []);
        $this->assertSame('q-shared', $sibling->nextState?->pendingHumanInputRequests[0]->questionId);
        $this->assertSame([], $sibling->nextState?->messages);
        $eventTypes = array_map(static fn (RunEvent $e): string => $e->type, $sibling->events);
        $this->assertSame(['tool_call_result_received', 'tool_execution_end'], $eventTypes);
        $this->assertNotContains(RunEventTypeEnum::ToolBatchCommitted->value, $eventTypes);
        $this->assertNotContains(RunEventTypeEnum::MessageEnd->value, $eventTypes);
    }

    public function testResumeRequeuesExactCallWithoutModelMessage(): void
    {
        $collector = new ToolBatchCollector(defaultMaxParallelism: 1);
        $call = $this->call('run-r', 'step-r', 'call-r', 0, 1);
        $collector->registerExpectedBatch('run-r', 1, 'step-r', [$call]);
        $collector->admitHumanInputSuspension('run-r', 1, 'step-r', 'call-r', 'q-r');

        $answer = new \Ineersa\AgentCore\Domain\Tool\ToolCallHumanInputAnswerDTO(
            questionId: 'q-r',
            answer: '✅ Allow',
            continuationRef: ['run_id' => 'run-r', 'turn_no' => 1, 'step_id' => 'step-r', 'tool_call_id' => 'call-r'],
            requestPayload: ['question_id' => 'q-r', 'prompt' => 'Allow?'],
        );
        $effects = $collector->resumeHumanInputAnswer('run-r', 1, 'step-r', 'call-r', 'q-r', $answer);
        $this->assertCount(1, $effects);
        $this->assertSame('call-r', $effects[0]->toolCallId);
        $this->assertSame($call->args, $effects[0]->args);
        $this->assertNotNull($effects[0]->humanInputAnswer);

        $store = new \Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore();
        $router = new \Ineersa\AgentCore\Application\Handler\CommandRouter(new \Ineersa\AgentCore\Application\Handler\CommandHandlerRegistry([]));
        $collector2 = new ToolBatchCollector();
        $collector2->registerExpectedBatch('run-h2', 2, 'step-h2', [$this->call('run-h2', 'step-h2', 'call-h2', 0, 2)]);
        $collector2->admitHumanInputSuspension('run-h2', 2, 'step-h2', 'call-h2', 'q-h2');
        $handler2 = new \Ineersa\AgentCore\Application\Pipeline\ApplyCommandHandler(
            commandStore: $store,
            commandRouter: $router,
            commandMailboxPolicy: new \Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy($store, $router),
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
            commandBus: new TestMessageBus(),
            toolBatchCollector: $collector2,
        );
        $state = RunStateBuilder::running('run-h2')
            ->withStatus(RunStatus::WaitingHuman)
            ->withTurnNo(2)
            ->withActiveStepId('step-h2')
            ->withPendingToolCalls(['call-h2' => false])
            ->withPendingHumanInputRequests([
                PendingHumanInputRequestDTO::toolCallFromPayload(
                    ['question_id' => 'q-h2', 'prompt' => 'Allow id?'],
                    ['run_id' => 'run-h2', 'turn_no' => 2, 'step_id' => 'step-h2', 'tool_call_id' => 'call-h2'],
                ),
            ])
            ->build();
        $result = $handler2->handle(new \Ineersa\AgentCore\Domain\Message\ApplyCommand(
            runId: 'run-h2',
            turnNo: 2,
            stepId: 'human-step',
            attempt: 1,
            idempotencyKey: 'human-q-h2',
            kind: \Ineersa\AgentCore\Domain\Command\CoreCommandKind::HumanResponse,
            payload: ['question_id' => 'q-h2', 'answer' => '✅ Allow'],
        ), $state);

        $this->assertSame(RunStatus::Running, $result->nextState?->status);
        $this->assertSame([], $result->nextState?->pendingHumanInputRequests);
        $this->assertSame($state->messages, $result->nextState?->messages);
        $this->assertCount(1, $result->postCommitEffects);
        $this->assertInstanceOf(ExecuteToolCall::class, $result->postCommitEffects[0]);
        $this->assertSame('call-h2', $result->postCommitEffects[0]->toolCallId);
        $this->assertArrayNotHasKey('message', $result->events[0]->payload);
        $this->assertNotEmpty($result->postCommit, 'markApplied must wait for post-commit after effects');
        $this->assertFalse($store->has('run-h2', 'human-q-h2'));
        foreach ($result->postCommit as $callback) {
            $callback();
        }
        $this->assertTrue($store->has('run-h2', 'human-q-h2'));

        // Identical resume while already inFlight returns the same effect (CAS retry safety).
        $same = $collector2->resumeHumanInputAnswer(
            'run-h2',
            2,
            'step-h2',
            'call-h2',
            'q-h2',
            new \Ineersa\AgentCore\Domain\Tool\ToolCallHumanInputAnswerDTO(
                questionId: 'q-h2',
                answer: '✅ Allow',
                continuationRef: ['run_id' => 'run-h2', 'turn_no' => 2, 'step_id' => 'step-h2', 'tool_call_id' => 'call-h2'],
                requestPayload: ['question_id' => 'q-h2', 'prompt' => 'Allow id?'],
            ),
        );
        $this->assertCount(1, $same);
        $this->assertSame('call-h2', $same[0]->toolCallId);

        // Durable redrive by question_id + answer after state already advanced.
        $redrive = $collector2->redriveHumanInputAnswer('run-h2', 2, 'step-h2', 'q-h2', '✅ Allow');
        $this->assertCount(1, $redrive);
        $this->assertSame('call-h2', $redrive[0]->toolCallId);
    }

    public function testMalformedToolCallContinuationRefIsRejectedAtConstructionAndAnswer(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PendingHumanInputRequestDTO::toolCallFromPayload(
            ['question_id' => 'q-bad'],
            ['run_id' => 'run-bad'], // missing turn/step/tool_call
        );
    }

    public function testCrossCorrelatedToolCallAnswerIsRejected(): void
    {
        $store = new \Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore();
        $router = new \Ineersa\AgentCore\Application\Handler\CommandRouter(new \Ineersa\AgentCore\Application\Handler\CommandHandlerRegistry([]));
        $collector = new ToolBatchCollector();
        $collector->registerExpectedBatch('run-x', 1, 'step-x', [$this->call('run-x', 'step-x', 'call-x', 0)]);
        $collector->admitHumanInputSuspension('run-x', 1, 'step-x', 'call-x', 'q-x');
        $handler = new \Ineersa\AgentCore\Application\Pipeline\ApplyCommandHandler(
            commandStore: $store,
            commandRouter: $router,
            commandMailboxPolicy: new \Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy($store, $router),
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
            commandBus: new TestMessageBus(),
            toolBatchCollector: $collector,
        );
        $state = RunStateBuilder::running('run-x')
            ->withStatus(RunStatus::WaitingHuman)
            ->withTurnNo(1)
            ->withActiveStepId('step-x')
            ->withPendingToolCalls(['call-x' => false])
            ->withPendingHumanInputRequests([
                PendingHumanInputRequestDTO::toolCallFromPayload(
                    ['question_id' => 'q-x', 'prompt' => 'Allow?'],
                    // wrong tool_call_id vs pendingToolCalls keys / batch
                    ['run_id' => 'run-x', 'turn_no' => 1, 'step_id' => 'step-x', 'tool_call_id' => 'call-other'],
                ),
            ])
            ->build();
        $result = $handler->handle(new \Ineersa\AgentCore\Domain\Message\ApplyCommand(
            runId: 'run-x',
            turnNo: 1,
            stepId: 'human-step',
            attempt: 1,
            idempotencyKey: 'human-q-x',
            kind: \Ineersa\AgentCore\Domain\Command\CoreCommandKind::HumanResponse,
            payload: ['question_id' => 'q-x', 'answer' => '✅ Allow'],
        ), $state);
        $this->assertSame(RunStatus::WaitingHuman, $result->nextState?->status);
        $this->assertSame(RunEventTypeEnum::AgentCommandRejected->value, $result->events[0]->type ?? null);
        $this->assertStringContainsString('continuation_ref', (string) $result->nextState?->errorMessage);
    }

    public function testSuspensionThenTerminalToolCallResultsBothCommitDespiteSharedExecuteKey(): void
    {
        $runStore = new \Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore();
        $eventStore = new \Ineersa\AgentCore\Tests\Support\InMemoryEventStore();
        $commandStore = new \Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore();
        $collector = new ToolBatchCollector();
        $execute = $this->call('run-seq', 'step-seq', 'call-seq', 0);
        $collector->registerExpectedBatch('run-seq', 1, 'step-seq', [$execute]);

        $running = RunStateBuilder::running('run-seq')
            ->withVersion(1)
            ->withTurnNo(1)
            ->withLastSeq(1)
            ->withActiveStepId('step-seq')
            ->withPendingToolCalls(['call-seq' => false])
            ->build();
        $this->assertTrue($runStore->compareAndSwap($running, 0));

        $handler = new ToolCallResultHandler($collector, new EventFactory(), new ToolCallExtractor(), new AgentMessageNormalizer());
        $processor = new \Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor(
            runStore: $runStore,
            idempotency: new \Ineersa\AgentCore\Application\Handler\MessageIdempotencyService(new InMemoryIdempotencyStore()),
            runLockManager: new \Ineersa\AgentCore\Application\Handler\RunLockManager(new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\InMemoryStore())),
            runCommit: new \Ineersa\AgentCore\Application\Pipeline\RunCommit(
                runStore: $runStore,
                eventStore: $eventStore,
                commandStore: $commandStore,
                hotPromptStateRebuilder: new class implements \Ineersa\AgentCore\Contract\Replay\HotPromptStateRebuilderInterface {
                    public function rebuildHotPromptState(string $runId): \Ineersa\AgentCore\Domain\Run\PromptState
                    {
                        return new \Ineersa\AgentCore\Domain\Run\PromptState(
                            runId: $runId,
                            source: 'test',
                            eventCount: 0,
                            lastSeq: 0,
                            missingSequences: [],
                            isContiguous: true,
                            tokenEstimate: 0,
                            messages: [],
                        );
                    }
                },
                stepDispatcher: new \Ineersa\AgentCore\Application\Handler\StepDispatcher(new TestMessageBus()),
                logger: new \Psr\Log\NullLogger(),
            ),
            stepDispatcher: new \Ineersa\AgentCore\Application\Handler\StepDispatcher(new TestMessageBus()),
            handlers: [$handler],
            logger: new \Psr\Log\NullLogger(),
        );

        $request = PendingHumanInputRequestDTO::toolCallFromPayload(
            ['question_id' => 'q-seq', 'prompt' => 'Allow?'],
            ['run_id' => 'run-seq', 'turn_no' => 1, 'step_id' => 'step-seq', 'tool_call_id' => 'call-seq'],
        );
        $suspension = ToolCallResultFactory::fromExecuteToolCallAndHumanInputSuspension(
            $execute,
            new ToolExecutionHumanInputSuspension($request),
        );
        $terminal = ToolCallResultFactory::fromExecuteToolCallAndToolResult(
            $execute,
            new \Ineersa\AgentCore\Domain\Tool\ToolResult('call-seq', 'write', [['type' => 'text', 'text' => 'ok']], isError: false),
        );

        $this->assertNotSame($suspension->idempotencyKey(), $terminal->idempotencyKey());

        $processor->process('result.tool', $suspension);
        $afterSuspension = $runStore->get('run-seq');
        $this->assertNotNull($afterSuspension);
        $this->assertSame(RunStatus::WaitingHuman, $afterSuspension->status);
        $this->assertSame(['call-seq' => false], $afterSuspension->pendingToolCalls);

        // Duplicate suspension must dedup without state change.
        $processor->process('result.tool', $suspension);
        $this->assertSame($afterSuspension->version, $runStore->get('run-seq')?->version);

        // Answer path clears WaitingHuman before the resumed terminal ToolCallResult arrives.
        $resumed = RunStateBuilder::running('run-seq')
            ->withVersion($afterSuspension->version)
            ->withTurnNo(1)
            ->withLastSeq($afterSuspension->lastSeq)
            ->withActiveStepId('step-seq')
            ->withPendingToolCalls(['call-seq' => false])
            ->withPendingHumanInputRequests([])
            ->withStatus(RunStatus::Running)
            ->build();
        $this->assertTrue($runStore->compareAndSwap($resumed, $afterSuspension->version));

        // Resume requeues the exact call into a fresh batch (as resumeHumanInputAnswer does).
        $collector->registerExpectedBatch('run-seq', 1, 'step-seq', [$execute]);

        $processor->process('result.tool', $terminal);
        $afterTerminal = $runStore->get('run-seq');
        $this->assertNotNull($afterTerminal);
        // Single-call batch finalizes to empty pendingToolCalls (complete path).
        $this->assertSame([], $afterTerminal->pendingToolCalls);
        $this->assertSame(RunStatus::Running, $afterTerminal->status);
        $this->assertGreaterThan($resumed->lastSeq, $afterTerminal->lastSeq);

        // Duplicate terminal must dedup (no further commit).
        $versionAfterTerminal = $afterTerminal->version;
        $processor->process('result.tool', $terminal);
        $this->assertSame($versionAfterTerminal, $runStore->get('run-seq')?->version);
    }

    public function testPostCommitEffectDispatchFailureRedrivesExactCallWithoutMarkingApplied(): void
    {
        $runStore = new \Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore();
        $eventStore = new \Ineersa\AgentCore\Tests\Support\InMemoryEventStore();
        $commandStore = new \Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore();
        $collector = new ToolBatchCollector();
        $collector->registerExpectedBatch('run-pc', 1, 'step-pc', [$this->call('run-pc', 'step-pc', 'call-pc', 0)]);
        $collector->admitHumanInputSuspension('run-pc', 1, 'step-pc', 'call-pc', 'q-pc');

        $waiting = RunStateBuilder::running('run-pc')
            ->withStatus(RunStatus::WaitingHuman)
            ->withVersion(1)
            ->withTurnNo(1)
            ->withLastSeq(1)
            ->withActiveStepId('step-pc')
            ->withPendingToolCalls(['call-pc' => false])
            ->withPendingHumanInputRequests([
                PendingHumanInputRequestDTO::toolCallFromPayload(
                    ['question_id' => 'q-pc', 'prompt' => 'Allow?'],
                    ['run_id' => 'run-pc', 'turn_no' => 1, 'step_id' => 'step-pc', 'tool_call_id' => 'call-pc'],
                ),
            ])
            ->build();
        $this->assertTrue($runStore->compareAndSwap($waiting, 0));

        $executionBus = new class implements \Symfony\Component\Messenger\MessageBusInterface {
            public int $attempts = 0;

            /** @var list<object> */
            public array $dispatched = [];

            public function dispatch(object $message, array $stamps = []): \Symfony\Component\Messenger\Envelope
            {
                ++$this->attempts;
                if (1 === $this->attempts) {
                    throw new \Symfony\Component\Messenger\Exception\TransportException('simulated post-commit dispatch crash');
                }
                $this->dispatched[] = $message;

                return new \Symfony\Component\Messenger\Envelope($message, $stamps);
            }
        };

        $router = new \Ineersa\AgentCore\Application\Handler\CommandRouter(new \Ineersa\AgentCore\Application\Handler\CommandHandlerRegistry([]));
        $handler = new \Ineersa\AgentCore\Application\Pipeline\ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $router,
            commandMailboxPolicy: new \Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy($commandStore, $router),
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
            commandBus: new TestMessageBus(),
            toolBatchCollector: $collector,
        );

        $processor = new \Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor(
            runStore: $runStore,
            idempotency: new \Ineersa\AgentCore\Application\Handler\MessageIdempotencyService(new InMemoryIdempotencyStore()),
            runLockManager: new \Ineersa\AgentCore\Application\Handler\RunLockManager(new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\InMemoryStore())),
            runCommit: new \Ineersa\AgentCore\Application\Pipeline\RunCommit(
                runStore: $runStore,
                eventStore: $eventStore,
                commandStore: $commandStore,
                hotPromptStateRebuilder: new class implements \Ineersa\AgentCore\Contract\Replay\HotPromptStateRebuilderInterface {
                    public function rebuildHotPromptState(string $runId): \Ineersa\AgentCore\Domain\Run\PromptState
                    {
                        return new \Ineersa\AgentCore\Domain\Run\PromptState(
                            runId: $runId,
                            source: 'test',
                            eventCount: 0,
                            lastSeq: 0,
                            missingSequences: [],
                            isContiguous: true,
                            tokenEstimate: 0,
                            messages: [],
                        );
                    }
                },
                stepDispatcher: new \Ineersa\AgentCore\Application\Handler\StepDispatcher($executionBus),
                logger: new \Psr\Log\NullLogger(),
            ),
            stepDispatcher: new \Ineersa\AgentCore\Application\Handler\StepDispatcher($executionBus),
            handlers: [$handler],
            logger: new \Psr\Log\NullLogger(),
        );

        $command = new \Ineersa\AgentCore\Domain\Message\ApplyCommand(
            runId: 'run-pc',
            turnNo: 1,
            stepId: 'human-step',
            attempt: 1,
            idempotencyKey: 'human-q-pc',
            kind: \Ineersa\AgentCore\Domain\Command\CoreCommandKind::HumanResponse,
            payload: ['question_id' => 'q-pc', 'answer' => '✅ Allow'],
        );

        try {
            $processor->process('command', $command);
            $this->fail('first process must throw when effect dispatch fails');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Failed to dispatch execution effect', $exception->getMessage());
        }

        $afterFail = $runStore->get('run-pc');
        $this->assertNotNull($afterFail);
        $this->assertSame(RunStatus::Running, $afterFail->status);
        $this->assertSame([], $afterFail->pendingHumanInputRequests);
        $this->assertFalse($commandStore->has('run-pc', 'human-q-pc'));

        $processor->process('command', $command);

        $this->assertTrue($commandStore->has('run-pc', 'human-q-pc'));
        $this->assertCount(1, $executionBus->dispatched);
        $this->assertInstanceOf(ExecuteToolCall::class, $executionBus->dispatched[0]);
        $this->assertSame('call-pc', $executionBus->dispatched[0]->toolCallId);
        $this->assertNotNull($executionBus->dispatched[0]->humanInputAnswer);
    }

    public function testMultiRequestPostCommitRedriveWhileSiblingStillWaiting(): void
    {
        $runStore = new \Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore();
        $eventStore = new \Ineersa\AgentCore\Tests\Support\InMemoryEventStore();
        $commandStore = new \Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore();
        $collector = new ToolBatchCollector(defaultMaxParallelism: 2);
        $collector->registerExpectedBatch('run-fifo', 1, 'step-fifo', [
            $this->call('run-fifo', 'step-fifo', 'call-q1', 0, 1, 'parallel', 2),
            $this->call('run-fifo', 'step-fifo', 'call-q2', 1, 1, 'parallel', 2),
        ]);
        $collector->admitHumanInputSuspension('run-fifo', 1, 'step-fifo', 'call-q1', 'q1');
        $collector->admitHumanInputSuspension('run-fifo', 1, 'step-fifo', 'call-q2', 'q2');

        $waiting = RunStateBuilder::running('run-fifo')
            ->withStatus(RunStatus::WaitingHuman)
            ->withVersion(1)
            ->withTurnNo(1)
            ->withLastSeq(1)
            ->withActiveStepId('step-fifo')
            ->withPendingToolCalls(['call-q1' => false, 'call-q2' => false])
            ->withPendingHumanInputRequests([
                PendingHumanInputRequestDTO::toolCallFromPayload(
                    ['question_id' => 'q1', 'prompt' => 'Allow q1?'],
                    ['run_id' => 'run-fifo', 'turn_no' => 1, 'step_id' => 'step-fifo', 'tool_call_id' => 'call-q1'],
                ),
                PendingHumanInputRequestDTO::toolCallFromPayload(
                    ['question_id' => 'q2', 'prompt' => 'Allow q2?'],
                    ['run_id' => 'run-fifo', 'turn_no' => 1, 'step_id' => 'step-fifo', 'tool_call_id' => 'call-q2'],
                ),
            ])
            ->build();
        $this->assertTrue($runStore->compareAndSwap($waiting, 0));

        $executionBus = new class implements \Symfony\Component\Messenger\MessageBusInterface {
            public int $attempts = 0;

            /** @var list<object> */
            public array $dispatched = [];

            public function dispatch(object $message, array $stamps = []): \Symfony\Component\Messenger\Envelope
            {
                ++$this->attempts;
                if (1 === $this->attempts) {
                    throw new \Symfony\Component\Messenger\Exception\TransportException('simulated post-commit dispatch crash');
                }
                $this->dispatched[] = $message;

                return new \Symfony\Component\Messenger\Envelope($message, $stamps);
            }
        };

        $router = new \Ineersa\AgentCore\Application\Handler\CommandRouter(new \Ineersa\AgentCore\Application\Handler\CommandHandlerRegistry([]));
        $handler = new \Ineersa\AgentCore\Application\Pipeline\ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $router,
            commandMailboxPolicy: new \Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy($commandStore, $router),
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
            commandBus: new TestMessageBus(),
            toolBatchCollector: $collector,
        );

        $processor = new \Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor(
            runStore: $runStore,
            idempotency: new \Ineersa\AgentCore\Application\Handler\MessageIdempotencyService(new InMemoryIdempotencyStore()),
            runLockManager: new \Ineersa\AgentCore\Application\Handler\RunLockManager(new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\InMemoryStore())),
            runCommit: new \Ineersa\AgentCore\Application\Pipeline\RunCommit(
                runStore: $runStore,
                eventStore: $eventStore,
                commandStore: $commandStore,
                hotPromptStateRebuilder: new class implements \Ineersa\AgentCore\Contract\Replay\HotPromptStateRebuilderInterface {
                    public function rebuildHotPromptState(string $runId): \Ineersa\AgentCore\Domain\Run\PromptState
                    {
                        return new \Ineersa\AgentCore\Domain\Run\PromptState(
                            runId: $runId,
                            source: 'test',
                            eventCount: 0,
                            lastSeq: 0,
                            missingSequences: [],
                            isContiguous: true,
                            tokenEstimate: 0,
                            messages: [],
                        );
                    }
                },
                stepDispatcher: new \Ineersa\AgentCore\Application\Handler\StepDispatcher($executionBus),
                logger: new \Psr\Log\NullLogger(),
            ),
            stepDispatcher: new \Ineersa\AgentCore\Application\Handler\StepDispatcher($executionBus),
            handlers: [$handler],
            logger: new \Psr\Log\NullLogger(),
        );

        $command = new \Ineersa\AgentCore\Domain\Message\ApplyCommand(
            runId: 'run-fifo',
            turnNo: 1,
            stepId: 'human-step',
            attempt: 1,
            idempotencyKey: 'human-q1',
            kind: \Ineersa\AgentCore\Domain\Command\CoreCommandKind::HumanResponse,
            payload: ['question_id' => 'q1', 'answer' => '✅ Allow'],
        );

        try {
            $processor->process('command', $command);
            $this->fail('first process must throw when effect dispatch fails');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Failed to dispatch execution effect', $exception->getMessage());
        }

        $afterFail = $runStore->get('run-fifo');
        $this->assertNotNull($afterFail);
        // q1 applied; q2 still pending → status remains WaitingHuman (the multi-request gap).
        $this->assertSame(RunStatus::WaitingHuman, $afterFail->status);
        $this->assertCount(1, $afterFail->pendingHumanInputRequests);
        $this->assertSame('q2', $afterFail->pendingHumanInputRequests[0]->questionId);
        $this->assertFalse($commandStore->has('run-fifo', 'human-q1'));

        $eventsBeforeRetry = \count($eventStore->allFor('run-fifo'));

        // Redelivery of q1 while active FIFO head is q2 must redrive q1 without re-answering q2.
        $processor->process('command', $command);

        $afterRetry = $runStore->get('run-fifo');
        $this->assertNotNull($afterRetry);
        $this->assertSame(RunStatus::WaitingHuman, $afterRetry->status);
        $this->assertCount(1, $afterRetry->pendingHumanInputRequests);
        $this->assertSame('q2', $afterRetry->pendingHumanInputRequests[0]->questionId);
        $this->assertSame($eventsBeforeRetry, \count($eventStore->allFor('run-fifo')), 'redrive must not emit duplicate state events');
        $this->assertTrue($commandStore->has('run-fifo', 'human-q1'));
        $this->assertCount(1, $executionBus->dispatched);
        $this->assertInstanceOf(ExecuteToolCall::class, $executionBus->dispatched[0]);
        $this->assertSame('call-q1', $executionBus->dispatched[0]->toolCallId);
        $this->assertNotNull($executionBus->dispatched[0]->humanInputAnswer);
        $this->assertSame('q1', $executionBus->dispatched[0]->humanInputAnswer?->questionId);
    }

    private function call(
        string $runId,
        string $stepId,
        string $toolCallId,
        int $orderIndex,
        int $turnNo = 1,
        string $mode = 'sequential',
        int $maxParallelism = 1,
    ): ExecuteToolCall {
        return new ExecuteToolCall($runId, $turnNo, $stepId, 1, 'exec-'.$toolCallId, $toolCallId, 'bash', [], $orderIndex, mode: $mode, maxParallelism: $maxParallelism);
    }
}
