<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\InProcess;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * Thesis: In-process select_history_turn MUST emit RunHistoryPositionChanged into the transient sink,
 * because RuntimeEventTranslator drops history_position_set canonical events — without this
 * emission the TUI poller never sees the position change and the transcript is
 * never rebuilt.
 *
 * This test would have FAILED before FIX 1: the send() match arm called
 * historySelectionService->selectPrompt() directly and discarded the result, so the
 * RunHistoryPositionChanged emission in handleInProcessSelectHistoryTurn() was dead code.
 *
 * Container-based: anonymous-class stubs for EventStoreInterface and
 * RunStoreInterface are injected once in setUpBeforeClass(), then
 * the real InProcessAgentSessionClient is exercised. The shared
 * InMemoryRuntimeEventSink is drained to assert RunHistoryPositionChanged.
 *
 * @coversNothing — covers the wiring contract between send() match arm,
 * handleInProcessSelectHistoryTurn(), and InMemoryRuntimeEventSink::emit().
 */
#[CoversNothing]
final class InProcessSelectHistoryTurnEmitsRunHistoryPositionChangedTest extends IsolatedKernelTestCase
{
    private const string RUN_ID = 'test-history-select-run';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $events = self::minimalSessionEvents();

        // ── Anonymous stub for EventStoreInterface ───────────────
        $eventStore = new class($events) implements EventStoreInterface {
            /** @param list<RunEvent> $events */
            public function __construct(private array $events)
            {
            }

            public function latestSequenceFor(string $runId): ?int
            {
                $events = $this->allFor($runId);

                return [] === $events ? null : $events[array_key_last($events)]->seq;
            }

            public function firstFor(string $runId): ?RunEvent
            {
                $events = $this->allFor($runId);

                return $events[0] ?? null;
            }

            public function rangeFor(string $runId, int $startSeq, int $endSeq): iterable
            {
                foreach ($this->events as $event) {
                    if ($event->seq >= $startSeq && $event->seq <= $endSeq) {
                        yield $event;
                    }
                }
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
    public function sendSelectHistoryTurnEmitsRunHistoryPositionChangedIntoSink(): void
    {
        /** @var InProcessAgentSessionClient $client */
        $client = self::getContainer()->get(InProcessAgentSessionClient::class);

        /** @var InMemoryRuntimeEventSink $sink */
        $sink = self::getContainer()->get(InMemoryRuntimeEventSink::class);

        // ── Exercise ─────────────────────────────────────────────
        $client->send(self::RUN_ID, new UserCommand(
            type: 'select_history_turn',
            payload: ['turn_no' => 1],
        ));

        // ── Assert ───────────────────────────────────────────────
        /** @var list<RuntimeEvent> $events */
        $events = iterator_to_array($sink->drain(self::RUN_ID));

        $this->assertCount(1, $events, 'Expected exactly one RunHistoryPositionChanged event in the transient sink');

        $event = $events[0];
        $this->assertSame(RuntimeEventTypeEnum::RunHistoryPositionChanged->value, $event->type);
        $this->assertSame(self::RUN_ID, $event->runId);
        // Selecting user prompt turn 1 positions before it (retained boundary 0).
        $this->assertSame(0, $event->payload['position_turn_no'] ?? null);
        $this->assertSame(1, $event->payload['selected_prompt_turn_no'] ?? null);
        $this->assertIsInt($event->payload['position_event_seq'] ?? null);
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
                payload: [
                    'payload' => ['messages' => [[
                        'role' => 'user',
                        'content' => [['type' => 'text', 'text' => 'First prompt']],
                    ]]],
                ],
                createdAt: new \DateTimeImmutable('2026-06-29T00:00:00Z'),
            ),
            new RunEvent(
                runId: self::RUN_ID,
                seq: 2,
                turnNo: 1,
                type: RunEventTypeEnum::TurnAdvanced->value,
                payload: ['turn_no' => 1],
                createdAt: new \DateTimeImmutable('2026-06-29T00:00:01Z'),
            ),
            new RunEvent(
                runId: self::RUN_ID,
                seq: 3,
                turnNo: 1,
                type: RunEventTypeEnum::HistoryPositionSet->value,
                payload: ['position_turn_no' => 1, 'previous_position_turn_no' => 0, 'reason' => 'continue'],
                createdAt: new \DateTimeImmutable('2026-06-29T00:00:02Z'),
            ),
        ];
    }
}
