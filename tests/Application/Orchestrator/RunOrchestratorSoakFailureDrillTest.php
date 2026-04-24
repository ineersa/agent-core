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
use Ineersa\AgentCore\Application\Orchestrator\AdvanceRunHandler;
use Ineersa\AgentCore\Application\Orchestrator\ApplyCommandHandler;
use Ineersa\AgentCore\Application\Orchestrator\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Orchestrator\LlmStepResultHandler;
use Ineersa\AgentCore\Application\Orchestrator\RunCommit;
use Ineersa\AgentCore\Application\Orchestrator\RunMessageProcessor;
use Ineersa\AgentCore\Application\Orchestrator\RunMessageStateTools;
use Ineersa\AgentCore\Application\Orchestrator\RunOrchestrator;
use Ineersa\AgentCore\Application\Orchestrator\StartRunHandler;
use Ineersa\AgentCore\Application\Orchestrator\ToolCallResultHandler;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Tests\Support\SymfonyAiTestMessages;
use Ineersa\AgentCore\Domain\Message\StartRunPayload;
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
use Ineersa\AgentCore\Tests\Support\TestSerializerFactory;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RunOrchestratorSoakFailureDrillTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir().'/agent-core-soak-'.uniqid('', true);
        mkdir($this->basePath, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);
    }

    public function testSoakProcessesOneThousandSyntheticRunsWithoutSequenceGaps(): void
    {
        $fixture = $this->createFixture();

        for ($index = 1; $index <= 1000; ++$index) {
            $runId = sprintf('run-soak-%04d', $index);
            $stepId = sprintf('turn-1-llm-%04d', $index);

            $fixture->orchestrator->onStartRun(new StartRun(
                runId: $runId,
                turnNo: 0,
                stepId: 'start-1',
                attempt: 1,
                idempotencyKey: sprintf('start-%04d', $index),
                payload: new StartRunPayload(messages: []),
            ));

            $fixture->orchestrator->onAdvanceRun(new AdvanceRun(
                runId: $runId,
                turnNo: 0,
                stepId: $stepId,
                attempt: 1,
                idempotencyKey: sprintf('advance-%04d', $index),
            ));

            $fixture->orchestrator->onLlmStepResult(new LlmStepResult(
                runId: $runId,
                turnNo: 1,
                stepId: $stepId,
                attempt: 1,
                idempotencyKey: sprintf('llm-%04d', $index),
                assistantMessage: SymfonyAiTestMessages::assistantText(sprintf('synthetic-run-%04d', $index)),
                usage: ['total_tokens' => 5],
                stopReason: 'stop',
                error: null,
            ));
        }

        for ($index = 1; $index <= 1000; ++$index) {
            $runId = sprintf('run-soak-%04d', $index);

            $state = $fixture->runStore->get($runId);
            self::assertNotNull($state);
            self::assertSame(RunStatus::Completed, $state->status);

            $events = $fixture->eventStore->allFor($runId);
            self::assertNotEmpty($events);

            $expectedSequence = 1;
            foreach ($events as $event) {
                self::assertSame($expectedSequence, $event->seq);
                ++$expectedSequence;
            }
        }
    }

    public function testDuplicateDeliveryStormDoesNotDuplicateToolCommitEvents(): void
    {
        $fixture = $this->createFixture();
        $runId = 'run-duplicate-storm-1';

        $fixture->orchestrator->onStartRun(new StartRun(
            runId: $runId,
            turnNo: 0,
            stepId: 'start-1',
            attempt: 1,
            idempotencyKey: 'start-storm-1',
            payload: new StartRunPayload(messages: []),
        ));

        $fixture->orchestrator->onAdvanceRun(new AdvanceRun(
            runId: $runId,
            turnNo: 0,
            stepId: 'turn-1-llm-1',
            attempt: 1,
            idempotencyKey: 'advance-storm-1',
        ));

        $fixture->orchestrator->onLlmStepResult(new LlmStepResult(
            runId: $runId,
            turnNo: 1,
            stepId: 'turn-1-llm-1',
            attempt: 1,
            idempotencyKey: 'llm-storm-1',
            assistantMessage: SymfonyAiTestMessages::assistantWithToolCalls([
                [
                    'id' => 'call-b',
                    'name' => 'beta',
                    'arguments' => ['query' => 'beta'],
                ],
                [
                    'id' => 'call-a',
                    'name' => 'alpha',
                    'arguments' => ['query' => 'alpha'],
                ],
            ]),
            usage: ['total_tokens' => 12],
            stopReason: 'tool_call',
            error: null,
        ));

        $toolResultA = fn (string $idempotencyKey): ToolCallResult => new ToolCallResult(
            runId: $runId,
            turnNo: 1,
            stepId: 'turn-1-llm-1',
            attempt: 1,
            idempotencyKey: $idempotencyKey,
            toolCallId: 'call-a',
            orderIndex: 0,
            result: ['tool_name' => 'alpha', 'content' => [['type' => 'text', 'text' => 'A']]],
            isError: false,
            error: null,
        );

        $toolResultB = fn (string $idempotencyKey): ToolCallResult => new ToolCallResult(
            runId: $runId,
            turnNo: 1,
            stepId: 'turn-1-llm-1',
            attempt: 1,
            idempotencyKey: $idempotencyKey,
            toolCallId: 'call-b',
            orderIndex: 1,
            result: ['tool_name' => 'beta', 'content' => [['type' => 'text', 'text' => 'B']]],
            isError: false,
            error: null,
        );

        $fixture->orchestrator->onToolCallResult($toolResultB('tool-b-0'));
        $fixture->orchestrator->onToolCallResult($toolResultA('tool-a-0'));

        for ($index = 1; $index <= 50; ++$index) {
            $fixture->orchestrator->onToolCallResult($toolResultA(sprintf('tool-a-dup-%d', $index)));
            $fixture->orchestrator->onToolCallResult($toolResultB(sprintf('tool-b-dup-%d', $index)));
        }

        $events = $fixture->eventStore->allFor($runId);

        $receivedEvents = array_values(array_filter(
            $events,
            static fn (RunEvent $event): bool => 'tool_call_result_received' === $event->type,
        ));
        self::assertCount(2, $receivedEvents);

        $batchEvents = array_values(array_filter(
            $events,
            static fn (RunEvent $event): bool => 'tool_batch_committed' === $event->type,
        ));
        self::assertCount(1, $batchEvents);

        $state = $fixture->runStore->get($runId);
        self::assertNotNull($state);

        $toolMessages = array_values(array_filter(
            $state->messages,
            static fn (object $message): bool => $message instanceof \Ineersa\AgentCore\Domain\Message\AgentMessage && 'tool' === $message->role,
        ));

        self::assertCount(2, $toolMessages);
        self::assertSame('call-a', $toolMessages[0]->toolCallId);
        self::assertSame('call-b', $toolMessages[1]->toolCallId);
    }

    public function testTransientEventStoreFailureDuringCommitRollsBackStateAndSupportsRetry(): void
    {
        $eventStore = new FailOnceEventStore(new RunEventStore());
        $fixture = $this->createFixture($eventStore);

        $start = new StartRun(
            runId: 'run-failure-drill-1',
            turnNo: 0,
            stepId: 'start-1',
            attempt: 1,
            idempotencyKey: 'start-failure-drill-1',
            payload: new StartRunPayload(messages: []),
        );

        $fixture->orchestrator->onStartRun($start);

        $rolledBackState = $fixture->runStore->get('run-failure-drill-1');
        self::assertNotNull($rolledBackState);
        self::assertSame(RunStatus::Queued, $rolledBackState->status);
        self::assertSame(0, $rolledBackState->version);
        self::assertSame(0, $rolledBackState->lastSeq);
        self::assertSame([], $eventStore->allFor('run-failure-drill-1'));

        $fixture->orchestrator->onStartRun($start);

        $state = $fixture->runStore->get('run-failure-drill-1');
        self::assertNotNull($state);
        self::assertSame(1, $state->version);
        self::assertSame(1, $state->lastSeq);
        self::assertSame(RunStatus::Running, $state->status);

        $events = $eventStore->allFor('run-failure-drill-1');
        self::assertCount(1, $events);
        self::assertSame(1, $events[0]->seq);
        self::assertSame('run_started', $events[0]->type);
    }

    private function createFixture(?EventStoreInterface $eventStore = null): SoakFailureDrillFixture
    {
        $filesystem = new Filesystem(new LocalFilesystemAdapter($this->basePath));

        $runStore = new InMemoryRunStore();
        $eventStore ??= new RunEventStore();
        $commandStore = new InMemoryCommandStore();

        $outboxStore = new InMemoryOutboxStore();
        $runLogWriter = new RunLogWriter($filesystem);
        $runEventPublisher = new RunEventPublisher();

        $outboxProjector = new OutboxProjector($outboxStore, $runLogWriter, $runEventPublisher);
        $replayService = new ReplayService($eventStore, new RunLogReader($filesystem), new HotPromptStateStore());

        $stepDispatcher = new StepDispatcher(new SoakFailureNullMessageBus(), new SoakFailureNullMessageBus());
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
        );
        $stateTools = new RunMessageStateTools();
        $toolBatchCollector = new ToolBatchCollector();

        $runCommit = new RunCommit(
            runStore: $runStore,
            eventStore: $eventStore,
            commandStore: $commandStore,
            outboxProjector: $outboxProjector,
            replayService: $replayService,
            stepDispatcher: $stepDispatcher,
        );

        $runMessageProcessor = new RunMessageProcessor(
            runStore: $runStore,
            idempotency: new MessageIdempotencyService(),
            runLockManager: new RunLockManager(new LockFactory(new InMemoryStore())),
            runCommit: $runCommit,
            stepDispatcher: $stepDispatcher,
            handlers: [
                new StartRunHandler(
                    stateTools: $stateTools,
                    normalizer: TestSerializerFactory::normalizer(),
                ),
                new ApplyCommandHandler(
                    commandStore: $commandStore,
                    commandRouter: $commandRouter,
                    commandMailboxPolicy: $commandMailboxPolicy,
                    stateTools: $stateTools,
                ),
                new AdvanceRunHandler(
                    commandMailboxPolicy: $commandMailboxPolicy,
                    stateTools: $stateTools,
                ),
                new LlmStepResultHandler(
                    toolBatchCollector: $toolBatchCollector,
                    commandMailboxPolicy: $commandMailboxPolicy,
                    stateTools: $stateTools,
                    stepDispatcher: $stepDispatcher,
                ),
                new ToolCallResultHandler(
                    toolBatchCollector: $toolBatchCollector,
                    stateTools: $stateTools,
                ),
            ],
        );

        $orchestrator = new RunOrchestrator(
            runMessageProcessor: $runMessageProcessor,
        );

        return new SoakFailureDrillFixture(
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

final readonly class SoakFailureDrillFixture
{
    public function __construct(
        public RunOrchestrator $orchestrator,
        public InMemoryRunStore $runStore,
        public EventStoreInterface $eventStore,
    ) {
    }
}

final class SoakFailureNullMessageBus implements MessageBusInterface
{
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        return new Envelope($message, $stamps);
    }
}

final class FailOnceEventStore implements EventStoreInterface
{
    private bool $failed = false;

    public function __construct(private readonly EventStoreInterface $inner)
    {
    }

    public function append(RunEvent $event): void
    {
        $this->failOnce();
        $this->inner->append($event);
    }

    public function appendMany(array $events): void
    {
        $this->failOnce();
        $this->inner->appendMany($events);
    }

    public function allFor(string $runId): array
    {
        return $this->inner->allFor($runId);
    }

    private function failOnce(): void
    {
        if ($this->failed) {
            return;
        }

        $this->failed = true;

        throw new \RuntimeException('Simulated transient event-store failure.');
    }
}
