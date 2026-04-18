<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\CommandHandlerRegistry;
use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Handler\MessageIdempotencyService;
use Ineersa\AgentCore\Application\Handler\OutboxProjector;
use Ineersa\AgentCore\Application\Handler\ReplayService;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Orchestrator\RunOrchestrator;
use Ineersa\AgentCore\Application\Reducer\RunReducer;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Mercure\RunEventPublisher;
use Ineersa\AgentCore\Infrastructure\Storage\HotPromptStateStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryOutboxStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Infrastructure\Storage\RunEventStore;
use Ineersa\AgentCore\Infrastructure\Storage\RunLogReader;
use Ineersa\AgentCore\Infrastructure\Storage\RunLogWriter;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RunOrchestratorTopologyTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir().'/agent-core-topology-'.uniqid('', true);
        mkdir($this->basePath, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);
    }

    public function testToolBatchOrderingAndDuplicateResultsAreIdempotent(): void
    {
        $fixture = $this->createFixture();
        $runId = 'run-topology-1';

        $fixture->orchestrator->onStartRun(new StartRun(
            runId: $runId,
            turnNo: 0,
            stepId: 'start-1',
            attempt: 1,
            idempotencyKey: 'start-idemp-1',
            payload: ['messages' => []],
        ));

        $fixture->orchestrator->onAdvanceRun(new AdvanceRun(
            runId: $runId,
            turnNo: 0,
            stepId: 'turn-1-llm-1',
            attempt: 1,
            idempotencyKey: 'advance-idemp-1',
        ));

        $fixture->orchestrator->onLlmStepResult(new LlmStepResult(
            runId: $runId,
            turnNo: 1,
            stepId: 'turn-1-llm-1',
            attempt: 1,
            idempotencyKey: 'llm-result-idemp-1',
            assistantMessage: [
                'role' => 'assistant',
                'content' => [],
                'tool_calls' => [
                    [
                        'id' => 'call-b',
                        'name' => 'beta',
                        'arguments' => ['query' => 'beta'],
                        'order_index' => 1,
                    ],
                    [
                        'id' => 'call-a',
                        'name' => 'alpha',
                        'arguments' => ['query' => 'alpha'],
                        'order_index' => 0,
                    ],
                ],
            ],
            usage: ['total_tokens' => 10],
            stopReason: 'tool_call',
            error: null,
        ));

        $fixture->orchestrator->onToolCallResult(new ToolCallResult(
            runId: $runId,
            turnNo: 1,
            stepId: 'turn-1-llm-1',
            attempt: 1,
            idempotencyKey: 'tool-call-b-1',
            toolCallId: 'call-b',
            orderIndex: 1,
            result: ['tool_name' => 'beta', 'content' => [['type' => 'text', 'text' => 'B']]],
            isError: false,
            error: null,
        ));

        $fixture->orchestrator->onToolCallResult(new ToolCallResult(
            runId: $runId,
            turnNo: 1,
            stepId: 'turn-1-llm-1',
            attempt: 1,
            idempotencyKey: 'tool-call-a-1',
            toolCallId: 'call-a',
            orderIndex: 0,
            result: ['tool_name' => 'alpha', 'content' => [['type' => 'text', 'text' => 'A']]],
            isError: false,
            error: null,
        ));

        $eventsBeforeDuplicate = $fixture->eventStore->allFor($runId);

        // Duplicate delivery should be idempotent.
        $fixture->orchestrator->onToolCallResult(new ToolCallResult(
            runId: $runId,
            turnNo: 1,
            stepId: 'turn-1-llm-1',
            attempt: 1,
            idempotencyKey: 'tool-call-a-1',
            toolCallId: 'call-a',
            orderIndex: 0,
            result: ['tool_name' => 'alpha', 'content' => [['type' => 'text', 'text' => 'A']]],
            isError: false,
            error: null,
        ));

        $eventsAfterDuplicate = $fixture->eventStore->allFor($runId);
        self::assertCount(count($eventsBeforeDuplicate), $eventsAfterDuplicate);

        $state = $fixture->runStore->get($runId);
        self::assertNotNull($state);

        $toolMessages = array_values(array_filter(
            $state->messages,
            static fn (object $message): bool => $message instanceof \Ineersa\AgentCore\Domain\Message\AgentMessage && 'tool' === $message->role,
        ));

        self::assertCount(2, $toolMessages);
        self::assertSame('call-a', $toolMessages[0]->toolCallId);
        self::assertSame('call-b', $toolMessages[1]->toolCallId);

        $receivedEvents = array_values(array_filter(
            $eventsAfterDuplicate,
            static fn (RunEvent $event): bool => 'tool_call_result_received' === $event->type,
        ));
        self::assertCount(2, $receivedEvents);

        $batchEvents = array_values(array_filter(
            $eventsAfterDuplicate,
            static fn (RunEvent $event): bool => 'tool_batch_committed' === $event->type,
        ));
        self::assertCount(1, $batchEvents);
    }

    public function testStaleToolResultWritesAuditEvent(): void
    {
        $fixture = $this->createFixture();
        $runId = 'run-topology-2';

        $fixture->orchestrator->onStartRun(new StartRun(
            runId: $runId,
            turnNo: 0,
            stepId: 'start-1',
            attempt: 1,
            idempotencyKey: 'start-idemp-1',
            payload: ['messages' => []],
        ));

        $fixture->orchestrator->onAdvanceRun(new AdvanceRun(
            runId: $runId,
            turnNo: 0,
            stepId: 'turn-1-llm-1',
            attempt: 1,
            idempotencyKey: 'advance-idemp-1',
        ));

        $fixture->orchestrator->onToolCallResult(new ToolCallResult(
            runId: $runId,
            turnNo: 1,
            stepId: 'stale-step',
            attempt: 1,
            idempotencyKey: 'stale-tool-1',
            toolCallId: 'call-stale',
            orderIndex: 0,
            result: null,
            isError: true,
            error: ['message' => 'late'],
        ));

        $staleEvents = array_values(array_filter(
            $fixture->eventStore->allFor($runId),
            static fn (RunEvent $event): bool => 'stale_result_ignored' === $event->type,
        ));

        self::assertCount(1, $staleEvents);
        self::assertSame('tool_call_result', $staleEvents[0]->payload['result']);
    }

    public function testAbortedLlmResultTransitionsRunToCancelled(): void
    {
        $fixture = $this->createFixture();
        $runId = 'run-topology-3';

        $fixture->orchestrator->onStartRun(new StartRun(
            runId: $runId,
            turnNo: 0,
            stepId: 'start-1',
            attempt: 1,
            idempotencyKey: 'start-idemp-1',
            payload: ['messages' => []],
        ));

        $fixture->orchestrator->onAdvanceRun(new AdvanceRun(
            runId: $runId,
            turnNo: 0,
            stepId: 'turn-1-llm-1',
            attempt: 1,
            idempotencyKey: 'advance-idemp-1',
        ));

        $fixture->orchestrator->onApplyCommand(new ApplyCommand(
            runId: $runId,
            turnNo: 1,
            stepId: 'cancel-1',
            attempt: 1,
            idempotencyKey: 'cancel-idemp-1',
            kind: 'cancel',
            payload: ['reason' => 'requested by user'],
        ));

        $fixture->orchestrator->onLlmStepResult(new LlmStepResult(
            runId: $runId,
            turnNo: 1,
            stepId: 'turn-1-llm-1',
            attempt: 1,
            idempotencyKey: 'llm-result-idemp-1',
            assistantMessage: [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'partial']],
            ],
            usage: ['total_tokens' => 5],
            stopReason: 'aborted',
            error: null,
        ));

        $state = $fixture->runStore->get($runId);
        self::assertNotNull($state);
        self::assertSame(RunStatus::Cancelled, $state->status);

        $abortedEvents = array_values(array_filter(
            $fixture->eventStore->allFor($runId),
            static fn (RunEvent $event): bool => 'llm_step_aborted' === $event->type,
        ));

        self::assertCount(1, $abortedEvents);
        self::assertSame('aborted', $abortedEvents[0]->payload['stop_reason']);
    }

    private function createFixture(): RunOrchestratorFixture
    {
        $filesystem = new Filesystem(new LocalFilesystemAdapter($this->basePath));

        $runStore = new InMemoryRunStore();
        $eventStore = new RunEventStore();
        $commandStore = new InMemoryCommandStore();

        $outboxStore = new InMemoryOutboxStore();
        $runLogWriter = new RunLogWriter($filesystem);
        $runEventPublisher = new RunEventPublisher();

        $outboxProjector = new OutboxProjector($outboxStore, $runLogWriter, $runEventPublisher);
        $replayService = new ReplayService($eventStore, new RunLogReader($filesystem), new HotPromptStateStore());

        $executionBus = new RecordingMessageBus();
        $publisherBus = new RecordingMessageBus();

        $orchestrator = new RunOrchestrator(
            runStore: $runStore,
            eventStore: $eventStore,
            commandStore: $commandStore,
            reducer: new RunReducer(),
            stepDispatcher: new StepDispatcher($executionBus, $publisherBus),
            commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
            outboxProjector: $outboxProjector,
            replayService: $replayService,
            idempotency: new MessageIdempotencyService(),
            runLockManager: new RunLockManager(new LockFactory(new InMemoryStore())),
            toolBatchCollector: new ToolBatchCollector(),
        );

        return new RunOrchestratorFixture(
            orchestrator: $orchestrator,
            runStore: $runStore,
            eventStore: $eventStore,
        );
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());

                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }
}

final readonly class RunOrchestratorFixture
{
    public function __construct(
        public RunOrchestrator $orchestrator,
        public InMemoryRunStore $runStore,
        public RunEventStore $eventStore,
    ) {
    }
}

final class RecordingMessageBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;

        return new Envelope($message, $stamps);
    }
}
