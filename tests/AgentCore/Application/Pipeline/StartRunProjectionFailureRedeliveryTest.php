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

        $processor = new RunMessageProcessor(
            activeRunContext: $activeRunContext,
            runLockManager: new RunLockManager(new LockFactory(new InMemoryStore())),
            runCommit: new RunCommit(
                activeRunContext: $activeRunContext,
                eventStore: $eventStore,
                stepDispatcher: new StepDispatcher(new TestMessageBus(), $executionBus),
                logger: new NullLogger(),
            ),
            stepDispatcher: new StepDispatcher(new TestMessageBus(), $executionBus),
            handlers: [
                new StartRunHandler(
                    eventFactory: new EventFactory(),
                    normalizer: TestSerializerFactory::normalizer(),
                    commandBus: $commandBus,
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
        $this->assertInstanceOf(AdvanceRun::class, $commandBus->messages[0]);
        $this->assertSame('run-start-projection-fail', $commandBus->messages[0]->runId());
        $this->assertStringStartsWith('start-follow-up-', $commandBus->messages[0]->stepId());
    }
}

/** @internal */
final class FailOnceProjectionActiveRunContext implements ActiveRunContextInterface
{
    /** @var array<string, RunState> */
    private array $states = [];
    private int $rememberFailuresRemaining = 1;

    public function stateFor(string $runId): RunState
    {
        return $this->states[$runId] ??= RunState::queued($runId);
    }

    public function remember(RunState $state): void
    {
        if ($this->rememberFailuresRemaining > 0) {
            --$this->rememberFailuresRemaining;
            unset($this->states[$state->runId]);

            throw new \RuntimeException('simulated projection lock');
        }

        $this->states[$state->runId] = $state;
    }

    public function invalidate(string $runId): void
    {
        unset($this->states[$runId]);
    }

    public function clear(): void
    {
        $this->states = [];
    }

    public function seed(RunState $state): void
    {
        $this->states[$state->runId] = $state;
    }
}
