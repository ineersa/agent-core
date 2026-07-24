<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Handler\HookSubscriberRegistry;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Application\Replay\PromptStateReplayService;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryPromptStateStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Session\Replay\SessionHotPromptReplayService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

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

        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(RunState::queued('child-run-1'), 0);
        $eventStore = new InMemoryEventStore();

        $replayService = new SessionHotPromptReplayService(
            eventStore: $eventStore,
            promptStateStore: new InMemoryPromptStateStore(),
            promptStateReplayService: new PromptStateReplayService(),
            replayEventPreparer: new ReplayEventPreparer(),
        );

        $commit = new RunCommit(
            runStore: $runStore,
            eventStore: $eventStore,
            commandStore: new InMemoryCommandStore(),
            hotPromptStateRebuilder: $replayService,
            stepDispatcher: new StepDispatcher(new TestMessageBus()),
            logger: new TestLogger(),
            hookDispatcher: new HookDispatcher(
                new HookSubscriberRegistry([$subscriber]),
                new EventDispatcher(),
                $this->createHookSerializer(),
                $this->createHookSerializer(),
            ),
        );

        $previous = $runStore->get('child-run-1');
        $this->assertNotNull($previous);
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

        $this->assertTrue($commit->commit($previous, $next, $events, []));
        $this->assertInstanceOf(AfterTurnCommitHookContext::class, $captured);
        $this->assertCount(2, $captured->events);
        $this->assertSame(1, $captured->events[0]->seq);
        $this->assertSame(2, $captured->events[1]->seq);
        $this->assertSame('running', $captured->status);
        $this->assertNotSame(0, $captured->events[0]->seq);
    }

    private function createHookSerializer(): Serializer
    {
        return new Serializer([
            new ArrayDenormalizer(),
            new ObjectNormalizer(new ClassMetadataFactory(new AttributeLoader())),
        ], [new JsonEncoder()]);
    }
}
