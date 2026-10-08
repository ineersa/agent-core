<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\AgentCore\Application\Pipeline\StartRunHandler;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\Builder\StartRunMessageBuilder;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\AgentCore\Tests\Support\TestSerializerFactory;
use Ineersa\AgentCore\Tests\Support\TestTransitionFinalizerFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Deterministic proof for the castor-check flake:
 * run_started can append, disposable projection persistence can then fail,
 * and Messenger StartRun redelivery must re-arm the initial AdvanceRun instead
 * of acknowledging a dead run.
 */
final class StartRunProjectionFailureRedeliveryTest extends TestCase
{
    public function testRedeliveryAfterProjectionFailureDispatchesInitialAdvanceWithoutDuplicatingRunStarted(): void
    {
        $eventStore = new InMemoryEventStore();
        $commandBus = new TestMessageBus();
        $executionBus = new TestMessageBus();
        $activeRunContext = new FailOnceProjectionActiveRunContext();
        $activeRunContext->createNew('run-start-projection-fail');
        $locks = new RunLockManager(new LockFactory(new InMemoryStore()));

        $processor = new RunMessageProcessor(
            activeRunContext: $activeRunContext,
            runLockManager: $locks,
            runCommit: new RunCommit(
                activeRunContext: $activeRunContext,
                eventStore: $eventStore,
                logger: new NullLogger(),
                executionOperations: new \Ineersa\AgentCore\Tests\Support\TestExecutionOperationStore(),
                sourceAcceptance: new \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance(new \Ineersa\AgentCore\Tests\Support\InMemoryCommandStore()),
                finalizer: TestTransitionFinalizerFactory::create($eventStore, new StepDispatcher($commandBus), commandBus: $commandBus, executionBus: $executionBus, locks: $locks),
            ),
            handlers: [
                new StartRunHandler(
                    eventFactory: new EventFactory(),
                    normalizer: TestSerializerFactory::normalizer(),
                ),
            ],
        );

        $message = StartRunMessageBuilder::create('run-start-projection-fail')
            ->withStepId('start-step')
            ->withIdempotencyKey('start-idempotency')
            ->build();

        try {
            $processor->process('command.start', $message);
            $this->fail('First StartRun must fail after canonical append when projection persistence fails.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulated projection lock', $exception->getMessage());
        }

        $this->assertCount(1, $eventStore->allFor('run-start-projection-fail'));
        $this->assertSame('run_started', $eventStore->allFor('run-start-projection-fail')[0]->type);
        $this->assertSame([], $commandBus->messages, 'Failed commit must not dispatch the initial AdvanceRun.');

        // Messenger retry observes the already-committed RunStarted model via replay.
        $activeRunContext->seed(RunState::queued('run-start-projection-fail')->with([
            'status' => RunStatus::Running,
            'version' => 1,
            'turnNo' => 0,
            'lastSeq' => 1,
            'activeStepId' => 'start-step',
            'model' => 'test-model',
        ]));

        $processor->process('command.start', $message);

        $this->assertCount(1, $eventStore->allFor('run-start-projection-fail'), 'Redelivery must not append a second run_started.');
        $this->assertCount(1, $commandBus->messages);
        $advance = $commandBus->messages[0] instanceof \Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO
            ? $commandBus->messages[0]->message
            : $commandBus->messages[0];
        $this->assertInstanceOf(AdvanceRun::class, $advance);
        $this->assertSame('run-start-projection-fail', $advance->runId());
        $this->assertStringStartsWith('start-follow-up-', $advance->stepId());
    }
}

/** @internal */
final class FailOnceProjectionActiveRunContext implements ActiveRunContextInterface
{
    /** @var array<string, RunState> */
    private array $states = [];
    private int $rememberFailuresRemaining = 1;

    public function createNew(string $runId): RunState
    {
        $state = RunState::queued($runId);
        $this->seed($state);

        return $state;
    }

    public function loadRecovered(RunState $state): void
    {
        $this->seed($state);
    }

    public function requireLoaded(string $runId): RunState
    {
        return $this->states[$runId] ?? throw new \Ineersa\AgentCore\Contract\RunContextNotLoadedException('Fixture not loaded');
    }

    public function replaceCurrent(RunState $state): void
    {
        if ($this->rememberFailuresRemaining > 0) {
            --$this->rememberFailuresRemaining;
            unset($this->states[$state->runId]);

            throw new \RuntimeException('simulated projection lock');
        }

        $this->states[$state->runId] = $state;
    }

    public function release(string $runId): void
    {
        unset($this->states[$runId]);
    }

    public function seed(RunState $state): void
    {
        $this->states[$state->runId] = $state;
    }
}
