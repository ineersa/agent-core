<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\AgentCore\Tests\Support\TestActiveRunContext;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
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
        $activeRunContext->remember($previous);
        $eventStore = new InMemoryEventStore();

        $commit = new RunCommit(
            activeRunContext: $activeRunContext,
            eventStore: $eventStore,
            commandStore: new InMemoryCommandStore(),
            stepDispatcher: new StepDispatcher(new TestMessageBus(), new TestMessageBus()),
            logger: new TestLogger(),
            hookDispatcher: new HookDispatcher([$subscriber]),
        );

        $next = new RunState(
            runId: 'child-run-1',
            status: RunStatus::Running,
            version: $previous->version + 1,
            turnNo: 1,
            lastSeq: 0,
            retryableFailure: true,
            retryAttempts: 3,
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
        // preserve the in-flight retry episode, not reset retryAttempts to 0.
        $persisted = $activeRunContext->stateFor('child-run-1');
        $this->assertSame(3, $persisted->retryAttempts);
        $this->assertTrue($persisted->retryableFailure);
    }
}
