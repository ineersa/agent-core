<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\CommandHandlerRegistry;
use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Handler\MessageIdempotencyService;
use Ineersa\AgentCore\Application\Handler\OutboxProjector;
use Ineersa\AgentCore\Application\Handler\ReplayService;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\RunMetrics;
use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Orchestrator\RunOrchestrator;
use Ineersa\AgentCore\Application\Reducer\RunReducer;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
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
use Stringable;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RunOrchestratorObservabilityTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir().'/agent-core-observability-'.uniqid('', true);
        mkdir($this->basePath, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);
    }

    public function testMetricsAndTracingAreRecordedAcrossTurnAndStaleResult(): void
    {
        $filesystem = new Filesystem(new LocalFilesystemAdapter($this->basePath));

        $runStore = new InMemoryRunStore();
        $eventStore = new RunEventStore();
        $commandStore = new InMemoryCommandStore();

        $outboxStore = new InMemoryOutboxStore();
        $runLogWriter = new RunLogWriter($filesystem);
        $runEventPublisher = new RunEventPublisher();

        $outboxProjector = new OutboxProjector($outboxStore, $runLogWriter, $runEventPublisher);

        $metrics = new RunMetrics();
        $traceLogger = new ObservabilityTraceLogger();
        $tracer = new RunTracer($traceLogger);

        $orchestrator = new RunOrchestrator(
            runStore: $runStore,
            eventStore: $eventStore,
            commandStore: $commandStore,
            reducer: new RunReducer(),
            stepDispatcher: new StepDispatcher(new ObservabilityNullMessageBus(), new ObservabilityNullMessageBus()),
            commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
            outboxProjector: $outboxProjector,
            replayService: new ReplayService($eventStore, new RunLogReader($filesystem), new HotPromptStateStore(), $metrics, $tracer),
            idempotency: new MessageIdempotencyService(),
            runLockManager: new RunLockManager(new LockFactory(new InMemoryStore())),
            toolBatchCollector: new ToolBatchCollector(),
            logger: $traceLogger,
            metrics: $metrics,
            tracer: $tracer,
        );

        $orchestrator->onStartRun(new StartRun(
            runId: 'run-obs-1',
            turnNo: 0,
            stepId: 'start-1',
            attempt: 1,
            idempotencyKey: 'start-1',
            payload: ['messages' => []],
        ));

        $orchestrator->onAdvanceRun(new AdvanceRun(
            runId: 'run-obs-1',
            turnNo: 0,
            stepId: 'turn-1',
            attempt: 1,
            idempotencyKey: 'advance-1',
        ));

        $orchestrator->onLlmStepResult(new LlmStepResult(
            runId: 'run-obs-1',
            turnNo: 1,
            stepId: 'stale-step',
            attempt: 1,
            idempotencyKey: 'llm-stale-1',
            assistantMessage: null,
            usage: [],
            stopReason: null,
            error: null,
        ));

        $orchestrator->onLlmStepResult(new LlmStepResult(
            runId: 'run-obs-1',
            turnNo: 1,
            stepId: 'turn-1',
            attempt: 1,
            idempotencyKey: 'llm-ok-1',
            assistantMessage: [
                'role' => 'assistant',
                'content' => [[
                    'type' => 'text',
                    'text' => 'done',
                ]],
            ],
            usage: [],
            stopReason: 'stop',
            error: null,
        ));

        $snapshot = $metrics->snapshot();

        self::assertSame(1, $snapshot['stale_result_count']);
        self::assertSame(1, $snapshot['turn_duration_ms']['count']);
        self::assertSame(1, $snapshot['active_runs_by_status']['completed']);

        $commitTraceFinishes = array_values(array_filter(
            $traceLogger->records,
            static fn (array $record): bool => 'agent_loop.trace.finish' === $record['message']
                && 'persistence.commit' === ($record['context']['span_name'] ?? null),
        ));

        self::assertNotEmpty($commitTraceFinishes);
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

final class ObservabilityNullMessageBus implements MessageBusInterface
{
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        return new Envelope($message, $stamps);
    }
}

final class ObservabilityTraceLogger extends AbstractLogger
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, Stringable|string $message, array $context = []): void
    {
        unset($level);

        $this->records[] = [
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
