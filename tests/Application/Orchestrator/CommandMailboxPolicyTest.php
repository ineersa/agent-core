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
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\StartRun;
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

final class CommandMailboxPolicyTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir().'/agent-core-mailbox-'.uniqid('', true);
        mkdir($this->basePath, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);
    }

    public function testSteerCommandsSupersedeWhenDrainModeOneAtATime(): void
    {
        $fixture = $this->createFixture(steerDrainMode: 'one_at_a_time');
        $runId = 'run-mailbox-steer';

        $fixture->orchestrator->onStartRun($this->startRun($runId));

        $fixture->orchestrator->onApplyCommand($this->steerCommand($runId, 'steer-1', 'first steer'));
        $fixture->orchestrator->onApplyCommand($this->steerCommand($runId, 'steer-2', 'latest steer'));

        $fixture->orchestrator->onAdvanceRun(new AdvanceRun(
            runId: $runId,
            turnNo: 0,
            stepId: 'advance-1',
            attempt: 1,
            idempotencyKey: 'advance-idemp-1',
        ));

        $state = $fixture->runStore->get($runId);
        self::assertNotNull($state);

        $userMessages = array_values(array_filter(
            $state->messages,
            static fn (object $message): bool => $message instanceof \Ineersa\AgentCore\Domain\Message\AgentMessage
                && 'user' === $message->role,
        ));

        self::assertCount(2, $userMessages);
        self::assertSame('latest steer', $userMessages[1]->content[0]['text']);

        $events = $fixture->eventStore->allFor($runId);

        $superseded = array_values(array_filter(
            $events,
            static fn (\Ineersa\AgentCore\Domain\Event\RunEvent $event): bool => 'agent_command_superseded' === $event->type
                && 'steer-1' === ($event->payload['idempotency_key'] ?? null),
        ));
        $applied = array_values(array_filter(
            $events,
            static fn (\Ineersa\AgentCore\Domain\Event\RunEvent $event): bool => 'agent_command_applied' === $event->type
                && 'steer-2' === ($event->payload['idempotency_key'] ?? null),
        ));

        self::assertCount(1, $superseded);
        self::assertCount(1, $applied);
    }

    public function testQueueCapRejectsNonCancelCommands(): void
    {
        $fixture = $this->createFixture(maxPendingCommands: 1);
        $runId = 'run-mailbox-cap';

        $fixture->orchestrator->onStartRun($this->startRun($runId));

        $fixture->orchestrator->onApplyCommand($this->steerCommand($runId, 'cap-steer-1', 'queued'));
        $fixture->orchestrator->onApplyCommand(new ApplyCommand(
            runId: $runId,
            turnNo: 0,
            stepId: 'follow-up-1',
            attempt: 1,
            idempotencyKey: 'cap-follow-up-1',
            kind: 'follow_up',
            payload: ['message' => [
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => 'follow-up']],
            ]],
        ));

        $rejections = array_values(array_filter(
            $fixture->eventStore->allFor($runId),
            static fn (\Ineersa\AgentCore\Domain\Event\RunEvent $event): bool => 'agent_command_rejected' === $event->type
                && 'cap-follow-up-1' === ($event->payload['idempotency_key'] ?? null),
        ));

        self::assertCount(1, $rejections);
        self::assertStringContainsString('mailbox cap', (string) $rejections[0]->payload['reason']);
    }

    public function testContinueIsRejectedWhenCancellationAlreadyInProgress(): void
    {
        $fixture = $this->createFixture();
        $runId = 'run-mailbox-cancel-vs-continue';

        $fixture->orchestrator->onStartRun($this->startRun($runId));
        $fixture->orchestrator->onAdvanceRun(new AdvanceRun(
            runId: $runId,
            turnNo: 0,
            stepId: 'advance-1',
            attempt: 1,
            idempotencyKey: 'advance-idemp-1',
        ));

        $fixture->orchestrator->onLlmStepResult(new LlmStepResult(
            runId: $runId,
            turnNo: 1,
            stepId: 'advance-1',
            attempt: 1,
            idempotencyKey: 'llm-failed-1',
            assistantMessage: null,
            usage: [],
            stopReason: 'error',
            error: ['message' => 'transient', 'retryable' => true],
        ));

        $fixture->orchestrator->onApplyCommand(new ApplyCommand(
            runId: $runId,
            turnNo: 1,
            stepId: 'cancel-1',
            attempt: 1,
            idempotencyKey: 'cancel-1',
            kind: 'cancel',
            payload: ['reason' => 'user cancel'],
        ));

        $fixture->orchestrator->onApplyCommand(new ApplyCommand(
            runId: $runId,
            turnNo: 1,
            stepId: 'continue-1',
            attempt: 1,
            idempotencyKey: 'continue-1',
            kind: 'continue',
            payload: [],
        ));

        $continueRejections = array_values(array_filter(
            $fixture->eventStore->allFor($runId),
            static fn (\Ineersa\AgentCore\Domain\Event\RunEvent $event): bool => 'agent_command_rejected' === $event->type
                && 'continue-1' === ($event->payload['idempotency_key'] ?? null),
        ));

        self::assertCount(1, $continueRejections);

        $state = $fixture->runStore->get($runId);
        self::assertNotNull($state);
        self::assertSame(RunStatus::Cancelling, $state->status);
    }

    public function testContinueSchedulesAdvanceForRetryableFailureWithValidLastRole(): void
    {
        $fixture = $this->createFixture();
        $runId = 'run-mailbox-continue';

        $fixture->orchestrator->onStartRun($this->startRun($runId));
        $fixture->orchestrator->onAdvanceRun(new AdvanceRun(
            runId: $runId,
            turnNo: 0,
            stepId: 'advance-1',
            attempt: 1,
            idempotencyKey: 'advance-idemp-1',
        ));

        $fixture->orchestrator->onLlmStepResult(new LlmStepResult(
            runId: $runId,
            turnNo: 1,
            stepId: 'advance-1',
            attempt: 1,
            idempotencyKey: 'llm-failed-1',
            assistantMessage: null,
            usage: [],
            stopReason: 'error',
            error: ['message' => 'transient', 'retryable' => true],
        ));

        $fixture->orchestrator->onApplyCommand(new ApplyCommand(
            runId: $runId,
            turnNo: 1,
            stepId: 'continue-1',
            attempt: 1,
            idempotencyKey: 'continue-1',
            kind: 'continue',
            payload: [],
        ));

        $state = $fixture->runStore->get($runId);
        self::assertNotNull($state);
        self::assertSame(RunStatus::Running, $state->status);

        $advanceCommands = array_values(array_filter(
            $fixture->commandBus->messages,
            static fn (object $message): bool => $message instanceof AdvanceRun,
        ));

        self::assertCount(1, $advanceCommands);
    }

    private function createFixture(int $maxPendingCommands = 100, string $steerDrainMode = 'one_at_a_time'): CommandMailboxFixture
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

        $commandBus = new MailboxRecordingMessageBus();
        $executionBus = new MailboxRecordingMessageBus();
        $publisherBus = new MailboxRecordingMessageBus();

        $stepDispatcher = new StepDispatcher($executionBus, $publisherBus);
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            steerDrainMode: $steerDrainMode,
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
                new StartRunHandler(stateTools: $stateTools),
                new ApplyCommandHandler(
                    commandStore: $commandStore,
                    commandRouter: $commandRouter,
                    commandMailboxPolicy: $commandMailboxPolicy,
                    stateTools: $stateTools,
                    maxPendingCommands: $maxPendingCommands,
                    commandBus: $commandBus,
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
                    commandBus: $commandBus,
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

        return new CommandMailboxFixture($orchestrator, $runStore, $eventStore, $commandStore, $commandBus);
    }

    private function startRun(string $runId): StartRun
    {
        return new StartRun(
            runId: $runId,
            turnNo: 0,
            stepId: 'start-1',
            attempt: 1,
            idempotencyKey: 'start-idemp-1',
            payload: [
                'messages' => [[
                    'role' => 'user',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'hello',
                    ]],
                ]],
            ],
        );
    }

    private function steerCommand(string $runId, string $idempotencyKey, string $text): ApplyCommand
    {
        return new ApplyCommand(
            runId: $runId,
            turnNo: 0,
            stepId: 'steer-'.$idempotencyKey,
            attempt: 1,
            idempotencyKey: $idempotencyKey,
            kind: 'steer',
            payload: [
                'message' => [
                    'role' => 'user',
                    'content' => [[
                        'type' => 'text',
                        'text' => $text,
                    ]],
                ],
            ],
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

final readonly class CommandMailboxFixture
{
    public function __construct(
        public RunOrchestrator $orchestrator,
        public InMemoryRunStore $runStore,
        public RunEventStore $eventStore,
        public InMemoryCommandStore $commandStore,
        public MailboxRecordingMessageBus $commandBus,
    ) {
    }
}

final class MailboxRecordingMessageBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;

        return new Envelope($message, $stamps);
    }
}
