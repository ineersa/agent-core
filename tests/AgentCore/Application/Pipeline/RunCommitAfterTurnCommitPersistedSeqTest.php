<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Contract\CanonicalSequenceBoundActionInterface;
use Ineersa\AgentCore\Contract\CoordinationActionValidatorInterface;
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
use PHPUnit\Framework\TestCase;

/**
 * Piece 3B1: AfterTurnCommit hook summaries must use allocated persisted seq, not input seq 0.
 */
final class RunCommitAfterTurnCommitPersistedSeqTest extends TestCase
{
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
    }
}

final class RunCommitFinishedChildReleaseTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('terminalStatuses')]
    public function testTerminalChildWithCapturedAfterTurnActionsLeavesOwnerRegistry(RunStatus $status): void
    {
        $action = new class implements CanonicalSequenceBoundActionInterface {
            public function bindCanonicalSequences(array $sequences): object
            {
                return $this;
            }
        };
        $subscriber = new class($action) implements EssentialAfterTurnHookInterface {
            public function __construct(private object $action)
            {
            }

            public function prepareAfterTurnCommit(AfterTurnCommitHookContext $context, int $predecessorSequence): array
            {
                return [$this->action];
            }
        };

        $activeRunContext = new TestActiveRunContext();
        $previous = new RunState(
            runId: 'child-run-1',
            status: RunStatus::Running,
            turnNo: 1,
            lastSeq: 1,
            model: 'test-model',
            parentRunId: 'parent-run-1',
        );
        $activeRunContext->loadRecovered($previous);
        $held = \WeakReference::create($previous);
        $eventStore = new InMemoryEventStore();
        $commandBus = new TestMessageBus();

        $commit = new RunCommit(
            activeRunContext: $activeRunContext,
            eventStore: $eventStore,
            logger: new TestLogger(),
            hookDispatcher: new HookDispatcher([$subscriber]),
            finalizer: TestTransitionFinalizerFactory::create(
                $eventStore,
                new StepDispatcher($commandBus, new TestMessageBus(), new TestLogger(), events: new \Symfony\Component\EventDispatcher\EventDispatcher()),
            ),
            actionValidator: new \Ineersa\AgentCore\Application\Handler\CoordinationActionValidator([
                new class implements CoordinationActionValidatorInterface {
                    public function supports(object $action): bool
                    {
                        return $action instanceof CanonicalSequenceBoundActionInterface;
                    }

                    public function validate(object $action): void
                    {
                    }
                },
            ]),
        );

        $next = $previous->with(['status' => $status]);
        $events = [
            new RunEvent('child-run-1', 0, 1, 'agent_end', ['reason' => $status->value]),
        ];

        $committed = $commit->commit($previous, $next, $events, []);
        $this->assertSame($status, $committed->status);
        $this->assertSame(2, $committed->lastSeq);
        $this->assertCount(1, $commandBus->messages);
        $this->assertSame($action, $commandBus->messages[0]);

        try {
            $activeRunContext->requireLoaded('child-run-1');
            $this->fail('Finished child must leave the owner registry after durable after-turn capture.');
        } catch (RunContextNotLoadedException) {
        }

        unset($previous, $next, $committed);
        gc_collect_cycles();
        $this->assertNull($held->get(), 'Released child RunState must become unreachable.');
    }

    public function testActiveChildAndParentTerminalWithoutAfterTurnActionsRemainLoaded(): void
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
            lastSeq: 1,
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

    public static function terminalStatuses(): iterable
    {
        yield 'completed' => [RunStatus::Completed];
        yield 'failed' => [RunStatus::Failed];
        yield 'cancelled' => [RunStatus::Cancelled];
    }
}
