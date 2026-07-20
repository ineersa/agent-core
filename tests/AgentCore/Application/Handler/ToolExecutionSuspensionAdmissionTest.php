<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ExecuteToolCallWorker;
use Ineersa\AgentCore\Application\Handler\MessageIdempotencyService;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\AgentCore\Application\Pipeline\RunOrchestrator;
use Ineersa\AgentCore\Application\Pipeline\ToolCallExtractor;
use Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler;
use Ineersa\AgentCore\Application\Pipeline\ToolExecutionSuspensionHandler;
use Ineersa\AgentCore\Application\Replay\PromptStateReplayService;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolExecutionSuspension;
use Ineersa\AgentCore\Domain\Run\HumanInputContinuationKindEnum;
use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionHumanInputSuspension;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryPromptStateStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Tests\Support\Builder\RunStateBuilder;
use Ineersa\AgentCore\Tests\Support\InMemoryDeferredToolCompletionRepository;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Session\Replay\SessionHotPromptReplayService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult as SymfonyToolResult;
use Symfony\AI\Platform\Result\ToolCall as SymfonyToolCall;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Slice B thesis: typed toolbox suspension is not a completed tool result;
 * run-control admission stores WaitingHuman + batch awaiting map; duplicates
 * are idempotent and conflicting request identities fail.
 */
final class ToolExecutionSuspensionAdmissionTest extends TestCase
{
    public function testTypedToolboxSuspensionDispatchesRunControlMessageWithoutRememberingResult(): void
    {
        $payload = $this->approvalPayload('req-susp-1', 'call-susp-1');
        $toolbox = new class($payload) implements ToolboxInterface {
            public function __construct(private readonly array $payload)
            {
            }

            public function getTools(): array
            {
                return [];
            }

            public function execute(SymfonyToolCall $toolCall): SymfonyToolResult
            {
                return new SymfonyToolResult(
                    $toolCall,
                    new ToolExecutionHumanInputSuspension(
                        PendingHumanInputRequestDTO::toolCallFromPayload(
                            payload: $this->payload,
                            continuationRef: [
                                'run_id' => 'run-susp-worker',
                                'turn_no' => 2,
                                'step_id' => 'turn-2-tools-1',
                                'tool_call_id' => $toolCall->getId(),
                            ],
                        ),
                    ),
                );
            }
        };

        $resultStore = new ToolExecutionResultStore();
        $executor = new ToolExecutor(
            defaultMode: 'parallel',
            defaultTimeoutSeconds: 30,
            maxParallelism: 2,
            resultStore: $resultStore,
            toolbox: $toolbox,
        );
        $commandBus = new TestMessageBus();
        $worker = new ExecuteToolCallWorker(
            $executor,
            $commandBus,
            new InMemoryDeferredToolCompletionRepository(),
        );

        $worker(new ExecuteToolCall(
            runId: 'run-susp-worker',
            turnNo: 2,
            stepId: 'turn-2-tools-1',
            attempt: 1,
            idempotencyKey: 'tool-susp-idemp',
            toolCallId: 'call-susp-1',
            toolName: 'bash',
            args: ['command' => 'env'],
            orderIndex: 0,
            toolIdempotencyKey: 'tool-inv-susp-1',
        ));

        $this->assertCount(1, $commandBus->messages);
        $this->assertInstanceOf(ToolExecutionSuspension::class, $commandBus->messages[0]);
        /** @var ToolExecutionSuspension $suspension */
        $suspension = $commandBus->messages[0];
        $this->assertSame('run-susp-worker', $suspension->runId());
        $this->assertSame(2, $suspension->turnNo());
        $this->assertSame('turn-2-tools-1', $suspension->stepId());
        $this->assertSame('call-susp-1', $suspension->toolCallId);
        $this->assertSame('req-susp-1', $suspension->request->questionId);
        $this->assertSame(HumanInputContinuationKindEnum::ToolCall, $suspension->request->continuationKind);
        $this->assertNull($resultStore->findByRunToolCall('run-susp-worker', 'call-susp-1'));
        $this->assertNull($resultStore->findByToolAndIdempotencyKey('bash', 'tool-inv-susp-1'));
    }

    public function testRunControlAdmissionStoresRequestBatchAwaitingMapAndWaitingHuman(): void
    {
        $runId = 'run-susp-admit';
        $stepId = 'turn-1-tools-1';
        $payload = $this->approvalPayload('req-admit-1', 'call-a');
        $request = PendingHumanInputRequestDTO::toolCallFromPayload(
            payload: $payload,
            continuationRef: [
                'run_id' => $runId,
                'turn_no' => 1,
                'step_id' => $stepId,
                'tool_call_id' => 'call-a',
            ],
        );

        $collector = new ToolBatchCollector(defaultMaxParallelism: 4);
        $initial = $collector->registerExpectedBatch($runId, 1, $stepId, [
            $this->executeToolCall($runId, $stepId, 'call-a', 0, 'parallel'),
            $this->executeToolCall($runId, $stepId, 'call-b', 1, 'parallel'),
        ]);
        $this->assertSame(['call-a', 'call-b'], array_map(
            static fn (ExecuteToolCall $call): string => $call->toolCallId,
            $initial,
        ));

        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(
            RunStateBuilder::running($runId)
                ->withTurnNo(1)
                ->withActiveStepId($stepId)
                ->withVersion(3)
                ->withLastSeq(5)
                ->withPendingToolCalls(['call-a' => false, 'call-b' => false])
                ->build(),
            0,
        );
        $eventStore = new InMemoryEventStore();
        $effectBus = new TestMessageBus();
        $orchestrator = $this->orchestrator($runStore, $eventStore, $collector, $effectBus);

        $orchestrator->onToolExecutionSuspension(new ToolExecutionSuspension(
            runId: $runId,
            turnNo: 1,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: 'susp-admit-1',
            toolCallId: 'call-a',
            orderIndex: 0,
            request: $request,
        ));

        $state = $runStore->get($runId);
        $this->assertNotNull($state);
        $this->assertSame(RunStatus::WaitingHuman, $state->status);
        $this->assertCount(1, $state->pendingHumanInputRequests);
        $this->assertSame('req-admit-1', $state->pendingHumanInputRequests[0]->questionId);
        $this->assertSame(HumanInputContinuationKindEnum::ToolCall, $state->pendingHumanInputRequests[0]->continuationKind);
        $this->assertSame($payload, $state->pendingHumanInputRequests[0]->payload);
        $this->assertSame(['call-a' => false, 'call-b' => false], $state->pendingToolCalls);

        $waiting = array_values(array_filter(
            $eventStore->allFor($runId),
            static fn ($event): bool => RunEventTypeEnum::WaitingHuman->value === $event->type,
        ));
        $this->assertCount(1, $waiting);
        $this->assertSame($payload, $waiting[0]->payload);

        $batch = $collector->batchSnapshot($runId, 1, $stepId);
        $this->assertNotNull($batch);
        $this->assertArrayNotHasKey('call-a', $batch->inFlight);
        $this->assertSame(['call-a' => 'req-admit-1'], $batch->awaitingHumanInput);
        $this->assertSame([], $batch->results);
    }

    public function testDuplicateAdmissionIsIdempotentAndConflictIsRejected(): void
    {
        $runId = 'run-susp-dup';
        $stepId = 'turn-1-tools-1';
        $payload = $this->approvalPayload('req-dup-1', 'call-x');
        $request = PendingHumanInputRequestDTO::toolCallFromPayload(
            payload: $payload,
            continuationRef: [
                'run_id' => $runId,
                'turn_no' => 1,
                'step_id' => $stepId,
                'tool_call_id' => 'call-x',
            ],
        );

        $collector = new ToolBatchCollector(defaultMaxParallelism: 2);
        $collector->registerExpectedBatch($runId, 1, $stepId, [
            $this->executeToolCall($runId, $stepId, 'call-x', 0, 'sequential'),
        ]);

        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(
            RunStateBuilder::running($runId)
                ->withTurnNo(1)
                ->withActiveStepId($stepId)
                ->withVersion(1)
                ->withLastSeq(1)
                ->withPendingToolCalls(['call-x' => false])
                ->build(),
            0,
        );
        $eventStore = new InMemoryEventStore();
        $orchestrator = $this->orchestrator($runStore, $eventStore, $collector, new TestMessageBus());

        $message = new ToolExecutionSuspension(
            runId: $runId,
            turnNo: 1,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: 'susp-dup-1',
            toolCallId: 'call-x',
            orderIndex: 0,
            request: $request,
        );
        $orchestrator->onToolExecutionSuspension($message);
        // Different Messenger idempotency key, same suspension identity → handler-level no-op.
        $orchestrator->onToolExecutionSuspension(new ToolExecutionSuspension(
            runId: $runId,
            turnNo: 1,
            stepId: $stepId,
            attempt: 2,
            idempotencyKey: 'susp-dup-2',
            toolCallId: 'call-x',
            orderIndex: 0,
            request: $request,
        ));

        $state = $runStore->get($runId);
        $this->assertNotNull($state);
        $this->assertCount(1, $state->pendingHumanInputRequests);
        $this->assertSame(1, \count(array_filter(
            $eventStore->allFor($runId),
            static fn ($event): bool => RunEventTypeEnum::WaitingHuman->value === $event->type,
        )));

        $conflict = PendingHumanInputRequestDTO::toolCallFromPayload(
            payload: $this->approvalPayload('req-other', 'call-x'),
            continuationRef: [
                'run_id' => $runId,
                'turn_no' => 1,
                'step_id' => $stepId,
                'tool_call_id' => 'call-x',
            ],
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Conflicting tool-execution suspension');
        $orchestrator->onToolExecutionSuspension(new ToolExecutionSuspension(
            runId: $runId,
            turnNo: 1,
            stepId: $stepId,
            attempt: 3,
            idempotencyKey: 'susp-conflict-1',
            toolCallId: 'call-x',
            orderIndex: 0,
            request: $conflict,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function approvalPayload(string $questionId, string $toolCallId): array
    {
        return [
            'kind' => 'approval',
            'question_id' => $questionId,
            'prompt' => 'Allow sensitive command?',
            'schema' => [
                'type' => 'string',
                'enum' => ['✅ Allow once', '📌 Always allow', '❌ Block'],
            ],
            'tool_call_id' => $toolCallId,
            'tool_name' => 'bash',
            'ui_kind' => 'choice',
        ];
    }

    private function executeToolCall(
        string $runId,
        string $stepId,
        string $toolCallId,
        int $orderIndex,
        string $mode,
    ): ExecuteToolCall {
        return new ExecuteToolCall(
            runId: $runId,
            turnNo: 1,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: hash('sha256', $runId.'|'.$toolCallId),
            toolCallId: $toolCallId,
            toolName: 'bash',
            args: ['command' => $toolCallId],
            orderIndex: $orderIndex,
            mode: $mode,
            maxParallelism: 4,
        );
    }

    private function orchestrator(
        InMemoryRunStore $runStore,
        InMemoryEventStore $eventStore,
        ToolBatchCollector $collector,
        TestMessageBus $effectBus,
    ): RunOrchestrator {
        $hotPrompt = new SessionHotPromptReplayService(
            eventStore: $eventStore,
            promptStateStore: new InMemoryPromptStateStore(),
            promptStateReplayService: new PromptStateReplayService(),
            replayEventPreparer: new ReplayEventPreparer(),
        );
        $stepDispatcher = new StepDispatcher($effectBus);
        $commit = new RunCommit(
            runStore: $runStore,
            eventStore: $eventStore,
            commandStore: new InMemoryCommandStore(),
            hotPromptStateRebuilder: $hotPrompt,
            stepDispatcher: $stepDispatcher,
            logger: new NullLogger(),
        );
        $processor = new RunMessageProcessor(
            runStore: $runStore,
            idempotency: new MessageIdempotencyService(new InMemoryIdempotencyStore()),
            runLockManager: new RunLockManager(new LockFactory(new InMemoryStore())),
            runCommit: $commit,
            stepDispatcher: $stepDispatcher,
            handlers: [
                new ToolExecutionSuspensionHandler(
                    toolBatchCollector: $collector,
                    eventFactory: new EventFactory(),
                ),
                new ToolCallResultHandler(
                    toolBatchCollector: $collector,
                    eventFactory: new EventFactory(),
                    toolCallExtractor: new ToolCallExtractor(),
                    messageNormalizer: new AgentMessageNormalizer(),
                ),
            ],
            logger: new NullLogger(),
        );

        return new RunOrchestrator($processor);
    }
}
