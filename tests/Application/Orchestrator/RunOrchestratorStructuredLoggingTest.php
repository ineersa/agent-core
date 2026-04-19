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
use Ineersa\AgentCore\Domain\Message\StartRun;
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
        $filesystem = new Filesystem(new LocalFilesystemAdapter($this->basePath));

        $runStore = new InMemoryRunStore();
        $eventStore = new RunEventStore();
        $commandStore = new InMemoryCommandStore();

        $outboxStore = new InMemoryOutboxStore();
        $runLogWriter = new RunLogWriter($filesystem);
        $runEventPublisher = new RunEventPublisher();

        $outboxProjector = new OutboxProjector($outboxStore, $runLogWriter, $runEventPublisher);
        $replayService = new ReplayService($eventStore, new RunLogReader($filesystem), new HotPromptStateStore());

        $logger = new RecordingStructuredLogger();

        $orchestrator = new RunOrchestrator(
            runStore: $runStore,
            eventStore: $eventStore,
            commandStore: $commandStore,
            reducer: new RunReducer(),
            stepDispatcher: new StepDispatcher(new NullMessageBus(), new NullMessageBus()),
            commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
            outboxProjector: $outboxProjector,
            replayService: $replayService,
            idempotency: new MessageIdempotencyService(),
            runLockManager: new RunLockManager(new LockFactory(new InMemoryStore())),
            toolBatchCollector: new ToolBatchCollector(),
            logger: $logger,
        );

        $orchestrator->onStartRun(new StartRun(
            runId: 'run-log-1',
            turnNo: 0,
            stepId: 'start-step-1',
            attempt: 1,
            idempotencyKey: 'start-idemp-1',
            payload: ['messages' => []],
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
