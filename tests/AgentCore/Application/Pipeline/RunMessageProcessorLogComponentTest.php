<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Dto\RunStateReplayResult;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\RunStateReplayException;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\AdvanceRunHandler;
use Ineersa\AgentCore\Application\Pipeline\LlmStepResultHandler;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Application\Pipeline\RunMessageHandler;
use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler;
use Ineersa\AgentCore\Application\Replay\PromptStateReplayService;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\CompactionStepResult;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryPromptStateStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Tests\Application\Handler\InMemoryIdempotencyStore;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Application\Pipeline\CompactionStepResultHandler;
use Ineersa\CodingAgent\Application\Pipeline\CompactRunHandler;
use Ineersa\CodingAgent\Logging\LogContextProcessor;
use Ineersa\CodingAgent\Session\History\HistoryProjector;
use Ineersa\CodingAgent\Session\History\HistoryReplayFilter;
use Ineersa\CodingAgent\Session\Replay\SessionHotPromptReplayService;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Regression: structured-log component attribution must come from the handler
 * itself ({@see RunMessageHandler::LOG_COMPONENT}), never from Core matching
 * CodingAgent class names.
 *
 * Exercises the compiled production registration path: the handler instances
 * come from the container's tagged RunMessageHandler iterator, and attribution
 * is observed inside {@see RunMessageProcessor::process()} at the exact point
 * where the ambient handler context is active.
 *
 * The run-state rebuilder double captures {@see RunLogContext::current()} and
 * aborts via the production replay-failure path, so heavy handler logic
 * (e.g. real compaction) never executes: attribution is established before
 * handle() runs.
 */
final class RunMessageProcessorLogComponentTest extends IsolatedKernelTestCase
{
    private const string SCOPE = 'log.component.test';

    protected function setUp(): void
    {
        RunLogContext::reset();
    }

    protected function tearDown(): void
    {
        RunLogContext::reset();
    }

    public function testCompactionHandlersRetainCompactionComponentThroughProductionProcessing(): void
    {
        [$processor, $log, $testHandler] = $this->createProcessor($this->productionHandlers());

        $compactRunId = 'run-attr-compact';
        $this->expectReplayAbort(static fn () => $processor->process(self::SCOPE, new CompactRun(
            runId: $compactRunId,
            turnNo: 0,
            stepId: 'compact-attr-1',
            attempt: 1,
            idempotencyKey: 'compact-attr-idem',
        )));

        $context = $log->capturedContexts[0];
        $this->assertSame('compaction', $context['component']);
        $this->assertSame(CompactRunHandler::class, $context['handler']);
        $this->assertSame($compactRunId, $context['run_id']);
        $this->assertSame(CompactRun::class, $context['message_type']);
        $this->assertSame(self::SCOPE, $context['scope']);
        $this->assertSame('agent.command.bus', $context['queue']);

        $stepRunId = 'run-attr-compaction-step';
        $this->expectReplayAbort(static fn () => $processor->process(self::SCOPE, new CompactionStepResult(
            runId: $stepRunId,
            turnNo: 0,
            stepId: 'compaction-step-attr-1',
            attempt: 1,
            idempotencyKey: 'compaction-step-attr-idem',
            summaryText: 'summary',
            error: null,
            retainedTailMessages: [],
            messagesCompacted: 3,
            messagesRetained: 1,
            firstRetainedIndex: 2,
            tokenEstimateBefore: 100,
            trigger: 'manual',
        )));

        $context = $log->capturedContexts[1];
        $this->assertSame('compaction', $context['component']);
        $this->assertSame(CompactionStepResultHandler::class, $context['handler']);
        $this->assertSame($stepRunId, $context['run_id']);
        $this->assertSame(CompactionStepResult::class, $context['message_type']);

        // The production error-path log record (replay failure) keeps the
        // correlation fields merged by LogContextProcessor.
        $records = $testHandler->getRecords();
        $this->assertCount(2, $records, 'Expected one replay-failure log record per processed message.');
        $this->assertSame('messenger.state.replay_failed', $records[0]->message);
        $this->assertSame($compactRunId, $records[0]->context['run_id']);
        $this->assertSame(CompactRunHandler::class, $records[0]->extra['handler']);
        $this->assertSame($stepRunId, $records[1]->context['run_id']);
    }

    public function testOrdinaryCoreHandlerRetainsRuntimeComponent(): void
    {
        [$processor, $log] = $this->createProcessor($this->productionHandlers());

        $runId = 'run-attr-advance';
        $this->expectReplayAbort(static fn () => $processor->process(self::SCOPE, new AdvanceRun(
            runId: $runId,
            turnNo: 0,
            stepId: 'advance-attr-1',
            attempt: 1,
            idempotencyKey: 'advance-attr-idem',
        )));

        $context = $log->capturedContexts[0];
        $this->assertSame('runtime', $context['component']);
        $this->assertSame(AdvanceRunHandler::class, $context['handler']);
        $this->assertSame($runId, $context['run_id']);
        $this->assertSame(AdvanceRun::class, $context['message_type']);
    }

    public function testProductionRegistrationCollectsAllHandlersWithDeclaredComponents(): void
    {
        $handlers = $this->productionHandlers();

        $this->assertCount(8, $handlers, 'Registered RunMessageHandler set changed; update this regression.');

        $byClass = [];
        foreach ($handlers as $handler) {
            $byClass[$handler::class] = $handler;
        }

        // App-owned compaction handlers are registered through the same tag and
        // declare their own component — Core never names them.
        $this->assertArrayHasKey(CompactRunHandler::class, $byClass);
        $this->assertArrayHasKey(CompactionStepResultHandler::class, $byClass);
        $this->assertSame('compaction', $byClass[CompactRunHandler::class]::LOG_COMPONENT);
        $this->assertSame('compaction', $byClass[CompactionStepResultHandler::class]::LOG_COMPONENT);

        // Core lane handlers keep their dedicated components; everything else
        // inherits the 'runtime' default.
        $this->assertArrayHasKey(LlmStepResultHandler::class, $byClass);
        $this->assertArrayHasKey(ToolCallResultHandler::class, $byClass);
        $this->assertSame('llm', $byClass[LlmStepResultHandler::class]::LOG_COMPONENT);
        $this->assertSame('tool', $byClass[ToolCallResultHandler::class]::LOG_COMPONENT);
    }

    /**
     * @return list<RunMessageHandler> handlers from the compiled container's
     *                                 tagged RunMessageHandler iterator
     */
    private function productionHandlers(): array
    {
        $processor = static::getContainer()->get(RunMessageProcessor::class);
        $property = new \ReflectionProperty(RunMessageProcessor::class, 'handlers');
        /** @var list<RunMessageHandler> $handlers */
        $handlers = $property->getValue($processor);

        return $handlers;
    }

    /**
     * Builds a RunMessageProcessor over the production-registered handlers with
     * in-memory stores and a capturing/aborting replay rebuilder.
     *
     * @param list<RunMessageHandler> $handlers
     *
     * @return array{RunMessageProcessor, CapturingRebuilder, TestHandler}
     */
    private function createProcessor(array $handlers): array
    {
        $runStore = new InMemoryRunStore();
        $eventStore = new InMemoryEventStore();
        $commandStore = new InMemoryCommandStore();

        $replayService = new SessionHotPromptReplayService(
            $eventStore,
            new InMemoryPromptStateStore(),
            new PromptStateReplayService(),
            new ReplayEventPreparer(),
            new HistoryReplayFilter(new HistoryProjector()),
        );

        $log = new CapturingRebuilder();
        $testHandler = new TestHandler();
        $logger = new Logger('test', [$testHandler]);
        $logger->pushProcessor(new LogContextProcessor());

        $processor = new RunMessageProcessor(
            runStore: $runStore,
            idempotency: new InMemoryIdempotencyStore(),
            runLockManager: new RunLockManager(new LockFactory(new InMemoryStore())),
            runCommit: new RunCommit(
                runStore: $runStore,
                eventStore: $eventStore,
                commandStore: $commandStore,
                hotPromptStateRebuilder: $replayService,
                stepDispatcher: new StepDispatcher(new TestMessageBus()),
                logger: new NullLogger(),
                hookDispatcher: null,
            ),
            stepDispatcher: new StepDispatcher(new TestMessageBus()),
            handlers: $handlers,
            logger: $logger,
            runStateRebuilder: $log,
        );

        return [$processor, $log, $testHandler];
    }

    private function expectReplayAbort(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected the capturing rebuilder to abort processing with RunStateReplayException.');
        } catch (RunStateReplayException) {
            // Expected: attribution was captured before the abort.
        }
    }
}

/**
 * Test double that captures the ambient {@see RunLogContext} at the point where
 * RunMessageProcessor has resolved the handler and entered its component
 * context, then aborts via the production replay-failure path so no handler
 * logic executes.
 */
final class CapturingRebuilder implements RunStateRebuilderInterface
{
    /** @var list<array<string, mixed>> */
    public array $capturedContexts = [];

    public function rebuildIfStale(RunState $state, string $runId): RunStateReplayResult
    {
        $this->capturedContexts[] = RunLogContext::current();

        throw new RunStateReplayException('test abort before handler execution');
    }

    public function rebuildAtPosition(RunState $state, string $runId, int $positionTurnNo): RunStateReplayResult
    {
        return RunStateReplayResult::noEvents();
    }
}
