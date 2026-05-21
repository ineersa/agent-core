<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\CommandHandlerRegistry;
use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Handler\MessageIdempotencyService;
use Ineersa\AgentCore\Application\Handler\ReplayService;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Pipeline\AdvanceRunHandler;
use Ineersa\AgentCore\Application\Pipeline\ApplyCommandHandler;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Pipeline\LlmStepResultHandler;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\AgentCore\Application\Pipeline\RunMessageStateTools;
use Ineersa\AgentCore\Application\Pipeline\RunOrchestrator;
use Ineersa\AgentCore\Application\Pipeline\StartRunHandler;
use Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Message\StartRunPayload;

use Ineersa\AgentCore\Infrastructure\Storage\HotPromptStateStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Infrastructure\Storage\RunEventStore;

use Ineersa\AgentCore\Tests\Support\TestSerializerFactory;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RunOrchestratorStructuredLoggingTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir().'/agent-core-structured-log-'.uniqid('', true);
        mkdir($this->basePath, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);
    }

    public function testCommitLogsStructuredEventContext(): void
    {
        $runStore = new InMemoryRunStore();
        $eventStore = new RunEventStore();
        $commandStore = new InMemoryCommandStore();

        $replayService = new ReplayService($eventStore, new HotPromptStateStore());

        $logger = new RecordingStructuredLogger();

        $stepDispatcher = new StepDispatcher(new NullMessageBus());
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
        );
        $stateTools = new RunMessageStateTools(new \Ineersa\AgentCore\Domain\Event\EventFactory(), new \Ineersa\AgentCore\Application\Pipeline\ToolCallExtractor());
        $toolBatchCollector = new ToolBatchCollector();

        $runCommit = new RunCommit(
            runStore: $runStore,
            eventStore: $eventStore,
            commandStore: $commandStore,
            replayService: $replayService,
            stepDispatcher: $stepDispatcher,
            hookDispatcher: null,
            logger: $logger,
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

        $orchestrator->onStartRun(new StartRun(
            runId: 'run-log-1',
            turnNo: 0,
            stepId: 'start-step-1',
            attempt: 1,
            idempotencyKey: 'start-idemp-1',
            payload: new StartRunPayload(messages: []),
        ));

        self::assertNotEmpty($logger->records);

        $record = $logger->records[0];

        self::assertSame('agent_loop.event', $record['message']);
        self::assertArrayHasKey('run_id', $record['context']);
        self::assertArrayHasKey('turn_no', $record['context']);
        self::assertArrayHasKey('step_id', $record['context']);
        self::assertArrayHasKey('seq', $record['context']);
        self::assertArrayHasKey('status', $record['context']);
        self::assertArrayHasKey('worker_id', $record['context']);
        self::assertArrayHasKey('attempt', $record['context']);

        self::assertSame('run-log-1', $record['context']['run_id']);
        self::assertSame('start-step-1', $record['context']['step_id']);
        self::assertSame('running', $record['context']['status']);
        self::assertSame('orchestrator', $record['context']['worker_id']);
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

final class NullMessageBus implements MessageBusInterface
{
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        return new Envelope($message, $stamps);
    }
}

final class RecordingStructuredLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
