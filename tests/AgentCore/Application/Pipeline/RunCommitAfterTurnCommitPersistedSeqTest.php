<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Contract\Extension\EssentialAfterTurnHookInterface;
use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Contract\RunContextNotLoadedException;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\AgentCore\Tests\Support\TestActiveRunContext;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\AgentCore\Tests\Support\TestTransitionFinalizerFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Piece 3B1: AfterTurnCommit hook summaries must use allocated persisted seq, not input seq 0.
 */
final class RunCommitAfterTurnCommitPersistedSeqTest extends TestCase
{
    public function testDiscardPreparesEssentialHooksWithoutAfterTurnObservers(): void
    {
        $subscriber = new class implements EssentialAfterTurnHookInterface, HookSubscriberInterface {
            public int $prepared = 0;

            public function prepareAfterTurnCommit(AfterTurnCommitHookContext $context, int $predecessorSequence): array
            {
                ++$this->prepared;

                return [];
            }

            public function handleAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
            {
                throw new \LogicException('Maintenance must not dispatch ordinary after-turn observers.');
            }
        };
        $state = new RunState('discard', RunStatus::Running);
        $context = new TestActiveRunContext();
        $context->loadRecovered($state);
        $events = new InMemoryEventStore();
        $commit = new RunCommit($context, $events, new TestLogger(), TestTransitionFinalizerFactory::create($events,
            new StepDispatcher(new TestMessageBus(), new TestMessageBus(), new TestLogger(), events: new \Symfony\Component\EventDispatcher\EventDispatcher())), new HookDispatcher([$subscriber]));
        $commit->commit($state, $state, [new RunEvent('discard', 0, 1, 'history_tail_discarded', [])], dispatchAfterTurnHooks: false);
        $this->assertSame(1, $subscriber->prepared);
    }

    public function testAfterTurnCommitHookReceivesPersistedSequencesNotInputZero(): void
    {
        $captured = null;
        $subscriber = new class($captured) implements HookSubscriberInterface {
            public function __construct(private ?AfterTurnCommitHookContext &$captured)
            {
            }

            public function handleAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
            {
                $this->captured = $context;

                return $context;
            }
        };

        $activeRunContext = new TestActiveRunContext();
        $previous = RunState::queued('child-run-1');
        $activeRunContext->loadRecovered($previous);
        $eventStore = new InMemoryEventStore();

        $commit = new RunCommit(
            activeRunContext: $activeRunContext,
            eventStore: $eventStore,
            logger: new TestLogger(),
            hookDispatcher: new HookDispatcher([$subscriber]),
            finalizer: TestTransitionFinalizerFactory::create($eventStore, new StepDispatcher(new TestMessageBus(), new TestMessageBus(), new TestLogger(), events: new \Symfony\Component\EventDispatcher\EventDispatcher())),
        );

        $next = new RunState(
            runId: 'child-run-1',
            status: RunStatus::Running,
            version: $previous->version + 1,
            turnNo: 1,
            lastSeq: 0,
            model: 'test-model');

        $events = [
            new RunEvent('child-run-1', 0, 1, 'llm_step_completed', ['usage' => ['input_tokens' => 10]]),
            new RunEvent('child-run-1', 0, 1, 'turn_advanced', ['turn_no' => 1]),
        ];

        $commit->commit($previous, $next, $events, []);
        $this->assertInstanceOf(AfterTurnCommitHookContext::class, $captured);
        $this->assertCount(2, $captured->events);
        $this->assertSame(1, $captured->events[0]->seq);
        $this->assertSame(2, $captured->events[1]->seq);
        $this->assertSame('running', $captured->status);
        $this->assertNotSame(0, $captured->events[0]->seq);
        $this->assertInstanceOf(RunState::class, $captured->runState);
        $this->assertSame(2, $captured->runState->lastSeq);

        // The assigned-sequence bump (input seq 0 -> persisted seq 2) must
        $persisted = $activeRunContext->requireLoaded('child-run-1');
        $this->assertSame(2, $persisted->lastSeq);
    }

    #[DataProvider('terminalStatuses')]
    public function testTerminalChildLeavesOwnerRegistryAfterVerifiedFinalization(RunStatus $status): void
    {
        $activeRunContext = new TestActiveRunContext();
        $previous = new RunState(
            runId: 'child-run-1',
            status: RunStatus::Running,
            turnNo: 1,
            lastSeq: 0,
            model: 'test-model',
            parentRunId: 'parent-run-1',
        );
        $activeRunContext->loadRecovered($previous);
        $held = \WeakReference::create($previous);
        $eventStore = new InMemoryEventStore();
        $commit = new RunCommit(
            activeRunContext: $activeRunContext,
            eventStore: $eventStore,
            logger: new TestLogger(),
            finalizer: TestTransitionFinalizerFactory::create(
                $eventStore,
                new StepDispatcher(new TestMessageBus(), new TestMessageBus(), new TestLogger(), events: new \Symfony\Component\EventDispatcher\EventDispatcher()),
            ),
        );

        $next = $previous->with(['status' => $status]);
        $committed = $commit->commit(
            $previous,
            $next,
            [new RunEvent('child-run-1', 0, 1, 'agent_end', ['reason' => $status->value])],
        );

        $this->assertSame($status, $committed->status);
        $this->assertSame(1, $committed->lastSeq);
        $this->expectException(RunContextNotLoadedException::class);
        try {
            $activeRunContext->requireLoaded('child-run-1');
        } finally {
            unset($previous, $next, $committed);
            gc_collect_cycles();
            $this->assertNull($held->get(), 'Released child RunState must become unreachable.');
        }
    }

    public function testActiveChildAndParentRemainLoaded(): void
    {
        $activeRunContext = new TestActiveRunContext();
        $eventStore = new InMemoryEventStore();
        $commit = new RunCommit(
            activeRunContext: $activeRunContext,
            eventStore: $eventStore,
            logger: new TestLogger(),
            finalizer: TestTransitionFinalizerFactory::create(
                $eventStore,
                new StepDispatcher(new TestMessageBus(), new TestMessageBus(), new TestLogger(), events: new \Symfony\Component\EventDispatcher\EventDispatcher()),
            ),
        );

        $activeChild = new RunState(
            runId: 'child-run-active',
            status: RunStatus::Running,
            turnNo: 1,
            lastSeq: 0,
            model: 'test-model',
            parentRunId: 'parent-run-1',
        );
        $activeRunContext->loadRecovered($activeChild);
        $commit->commit(
            $activeChild,
            $activeChild->with(['status' => RunStatus::WaitingHuman]),
            [new RunEvent('child-run-active', 0, 1, 'human_input.requested', [])],
        );
        $this->assertSame(RunStatus::WaitingHuman, $activeRunContext->requireLoaded('child-run-active')->status);

        $parent = new RunState(
            runId: 'parent-run-1',
            status: RunStatus::Running,
            turnNo: 2,
            lastSeq: 4,
            model: 'test-model',
        );
        $activeRunContext->loadRecovered($parent);
        $commit->commit(
            $parent,
            $parent->with(['status' => RunStatus::Completed]),
            [new RunEvent('parent-run-1', 0, 2, 'agent_end', ['reason' => 'completed'])],
        );
        $this->assertSame(RunStatus::Completed, $activeRunContext->requireLoaded('parent-run-1')->status);
    }

    public function testFailedFinalizationKeepsFinishedChildLoaded(): void
    {
        $activeRunContext = new TestActiveRunContext();
        $previous = new RunState(
            runId: 'child-run-1',
            status: RunStatus::Running,
            turnNo: 1,
            lastSeq: 0,
            model: 'test-model',
            parentRunId: 'parent-run-1',
        );
        $activeRunContext->loadRecovered($previous);
        $store = $this->createMock(\Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface::class);
        $store->expects($this->atLeastOnce())->method('assertTransitionReady');
        $store->expects($this->once())->method('appendTransition')->willReturnCallback(static function (array $events, array $work): array {
            return [new RunEvent($work['run_id'], 1, 1, $events[0]->type, $events[0]->payload)];
        });
        $store->expects($this->atLeastOnce())->method('verifiedPendingTransition')->willReturn(new \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO(
            'identity',
            ['run_id' => 'child-run-1', 'predecessor_seq' => 0, 'effects' => [], 'post_commit_effects' => [], 'actions' => [], 'after_turn_actions' => []],
            [1],
        ));
        $store->expects($this->once())->method('finalizeVerifiedTransition')->willThrowException(new \RuntimeException('finalize failed'));

        $commit = new RunCommit(
            activeRunContext: $activeRunContext,
            eventStore: $store,
            logger: new TestLogger(),
            finalizer: TestTransitionFinalizerFactory::create(
                $store,
                new StepDispatcher(new TestMessageBus(), new TestMessageBus(), new TestLogger(), events: new \Symfony\Component\EventDispatcher\EventDispatcher()),
            ),
        );

        try {
            $commit->commit(
                $previous,
                $previous->with(['status' => RunStatus::Completed]),
                [new RunEvent('child-run-1', 0, 1, 'agent_end', ['reason' => 'completed'])],
            );
            $this->fail('Failed finalization must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('finalize failed', $exception->getMessage());
        }

        $this->assertSame(RunStatus::Completed, $activeRunContext->requireLoaded('child-run-1')->status);
    }

    /**
     * @return iterable<string, array{0: RunStatus}>
     */
    public static function terminalStatuses(): iterable
    {
        yield 'completed' => [RunStatus::Completed];
        yield 'failed' => [RunStatus::Failed];
        yield 'cancelled' => [RunStatus::Cancelled];
    }
}
