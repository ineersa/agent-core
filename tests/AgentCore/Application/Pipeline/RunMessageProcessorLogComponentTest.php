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
use Ineersa\AgentCore\Application\Pipeline\RunMessageHandlerLogComponentInterface;
use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler;
use Ineersa\AgentCore\Contract\Replay\HotPromptStateRebuilderInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\CompactionStepResult;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Domain\Run\PromptState;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Tests\Application\Handler\InMemoryIdempotencyStore;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Application\Pipeline\CompactionStepResultHandler;
use Ineersa\CodingAgent\Application\Pipeline\CompactRunHandler;
use Ineersa\CodingAgent\Logging\LogContextProcessor;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Regression: structured-log component attribution must come from the handler
 * itself ({@see RunMessageHandlerLogComponentInterface}), never from Core
 * matching CodingAgent class names.
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

    public function testCompactionHandlersRetainCompactionComponentThroughProductionRegisteredHandlers(): void
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

    public function testLaneHandlersExposeDedicatedComponentsThroughProductionRegistration(): void
    {
        $llm = $this->findHandler(LlmStepResultHandler::class);
        $tool = $this->findHandler(ToolCallResultHandler::class);

        $this->assertInstanceOf(RunMessageHandlerLogComponentInterface::class, $llm);
        $this->assertSame('llm', $llm->getLogComponent());
        $this->assertInstanceOf(RunMessageHandlerLogComponentInterface::class, $tool);
        $this->assertSame('tool', $tool->getLogComponent());
    }

    private function findHandler(string $class): RunMessageHandler
    {
        foreach ($this->productionHandlers() as $handler) {
            if ($handler::class === $class) {
                return $handler;
            }
        }

        throw new \LogicException(\sprintf('Production RunMessageHandler registration does not contain %s.', $class));
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
                hotPromptStateRebuilder: new class implements HotPromptStateRebuilderInterface {
                    public function rebuildHotPromptState(RunState $state): PromptState
                    {
                        return new PromptState(
                            runId: $state->runId,
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
