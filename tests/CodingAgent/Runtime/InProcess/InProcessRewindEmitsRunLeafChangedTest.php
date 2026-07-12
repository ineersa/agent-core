<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\InProcess;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Runtime\Contract\CommittedEventStoreInterface;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * Thesis: In-process rewind MUST emit RunLeafChanged into the transient sink,
 * because RuntimeEventTranslator drops LeafSet canonical events — without this
 * emission the TUI poller never sees the leaf change and the transcript is
 * never rebuilt.
 *
 * This test would have FAILED before FIX 1: the send() match arm called
 * runRewindService->rewind() directly and discarded the result, so the
 * RunLeafChanged emission in handleInProcessRewind() was dead code.
 *
 * Container-based: anonymous-class stubs for EventStoreInterface and
 * RunStoreInterface are injected once in setUpBeforeClass(), then
 * the real InProcessAgentSessionClient is exercised. The shared
 * InMemoryRuntimeEventSink is drained to assert RunLeafChanged.
 *
 * @coversNothing — covers the wiring contract between send() match arm,
 * handleInProcessRewind(), and InMemoryRuntimeEventSink::emit().
 */
#[CoversNothing]
final class InProcessRewindEmitsRunLeafChangedTest extends IsolatedKernelTestCase
{
    private const string RUN_ID = 'test-rewind-run';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $events = self::minimalSessionEvents();

        // ── Anonymous stub for EventStoreInterface ───────────────
        $eventStore = new class($events) implements CommittedEventStoreInterface {
            /** @param list<RunEvent> $events */
            public function __construct(private array $events)
            {
            }

            public function allFor(string $runId): array
            {
                return $this->events;
            }

            public function append(RunEvent $event): RunEvent
            {
                $max = 0;
                foreach ($this->events as $existing) {
                    if ($existing->runId === $event->runId && $existing->seq > $max) {
                        $max = $existing->seq;
                    }
                }
                $persisted = new RunEvent($event->runId, $max + 1, $event->turnNo, $event->type, $event->payload, $event->createdAt);
                $this->events[] = $persisted;

                return $persisted;
            }

            public function appendMany(array $events): array
            {
                $out = [];
                foreach ($events as $event) {
                    $out[] = $this->append($event);
                }

                return $out;
            }
        };
        self::getContainer()->set(EventStoreInterface::class, $eventStore);

        // ── Anonymous stub for RunStoreInterface ─────────────────
        $runState = RunState::queued(self::RUN_ID);
        $runStore = new class($runState) implements RunStoreInterface {
            public function __construct(private RunState $state)
            {
            }

            public function get(string $runId): ?RunState
            {
                return $this->state;
            }

            public function compareAndSwap(RunState $state, int $expectedVersion): bool
            {
                return true;
            }

            public function findRunningStaleBefore(\DateTimeImmutable $updatedBefore): array
            {
                return [];
            }
        };
        self::getContainer()->set(RunStoreInterface::class, $runStore);
    }

    #[Test]
    public function sendRewindToTurnEmitsRunLeafChangedIntoSink(): void
    {
        /** @var InProcessAgentSessionClient $client */
        $client = self::getContainer()->get(InProcessAgentSessionClient::class);

        /** @var InMemoryRuntimeEventSink $sink */
        $sink = self::getContainer()->get(InMemoryRuntimeEventSink::class);

        // ── Exercise ─────────────────────────────────────────────
        $client->send(self::RUN_ID, new UserCommand(
            type: 'rewind_to_turn',
            payload: ['turn_no' => 1],
        ));

        // ── Assert ───────────────────────────────────────────────
        /** @var list<RuntimeEvent> $events */
        $events = iterator_to_array($sink->drain(self::RUN_ID));

        $this->assertCount(1, $events, 'Expected exactly one RunLeafChanged event in the transient sink');

        $event = $events[0];
        $this->assertSame(RuntimeEventTypeEnum::RunLeafChanged->value, $event->type);
        $this->assertSame(self::RUN_ID, $event->runId);
        $this->assertSame(1, $event->payload['turn_no'] ?? null);
        $this->assertIsInt($event->payload['leaf_set_seq'] ?? null);
    }

    /**
     * Minimal events forming a valid session with turns 0 and 1.
     * Sequences must be contiguous without gaps.
     *
     * @return list<RunEvent>
     */
    private static function minimalSessionEvents(): array
    {
        return [
            new RunEvent(
                runId: self::RUN_ID,
                seq: 1,
                turnNo: 0,
                type: RunEventTypeEnum::RunStarted->value,
                payload: [],
                createdAt: new \DateTimeImmutable('2026-06-29T00:00:00Z'),
            ),
            new RunEvent(
                runId: self::RUN_ID,
                seq: 2,
                turnNo: 1,
                type: RunEventTypeEnum::TurnAdvanced->value,
                // No parent_turn_no — LeafSet in the next event triggers
                // explicit-tree mode, but without parent_turn_no on the
                // TurnAdvanced, turn 1 becomes a root (parentTurnNo=null).
                // This avoids "Dangling parent_turn_no 0" in walkActivePath
                // because RunStarted (turnNo=0) doesn't create a turnInfo node.
                payload: ['turn_no' => 1],
                createdAt: new \DateTimeImmutable('2026-06-29T00:00:01Z'),
            ),
            new RunEvent(
                runId: self::RUN_ID,
                seq: 3,
                turnNo: 1,
                type: RunEventTypeEnum::LeafSet->value,
                payload: ['turn_no' => 1, 'previous_turn_no' => 0, 'parent_turn_no' => 0, 'reason' => 'advance'],
                createdAt: new \DateTimeImmutable('2026-06-29T00:00:02Z'),
            ),
        ];
    }
}
