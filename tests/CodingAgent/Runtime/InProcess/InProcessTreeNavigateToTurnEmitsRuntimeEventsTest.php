<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\InProcess;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Rewind\ConversationRewindInterface;
use Ineersa\CodingAgent\Rewind\FileRewindCheckpointService;
use Ineersa\CodingAgent\Rewind\TreeNavigateToTurnOrchestrator;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * Thesis: in-process tree_navigate_to_turn must emit the same transient runtime
 * events as process-mode TreeNavigateToTurnHandler for TUI poller parity:
 * RunLeafChanged for keep/restore conversation navigation, StatusUpdated for undo.
 */
#[CoversNothing]
final class InProcessTreeNavigateToTurnEmitsRuntimeEventsTest extends IsolatedKernelTestCase
{
    private const string RUN_ID = 'test-tree-nav-run';

    /** @return list<RunEvent> */
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

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $events = self::minimalSessionEvents();

        $eventStore = new class($events) implements EventStoreInterface {
            /** @param list<RunEvent> $events */
            public function __construct(private array $events)
            {
            }

            public function allFor(string $runId): array
            {
                return $this->events;
            }

            public function append(RunEvent $event): void
            {
            }

            public function appendMany(array $events): void
            {
            }
        };
        self::getContainer()->set(EventStoreInterface::class, $eventStore);

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

        $checkpoint = new class extends FileRewindCheckpointService {
            public function __construct()
            {
            }

            public function isOperational(): bool
            {
                return true;
            }

            public function restoreForTurn(string $runId, int $targetTurnNo): void
            {
            }

            public function undoLastRestore(string $runId): void
            {
            }
        };

        $rewind = new class implements ConversationRewindInterface {
            public function rewind(string $runId, int $targetTurnNo): array
            {
                return ['leafSetSeq' => 42];
            }
        };

        self::getContainer()->set(
            TreeNavigateToTurnOrchestrator::class,
            new TreeNavigateToTurnOrchestrator($checkpoint, $rewind),
        );
    }

    #[Test]
    public function sendTreeNavigateKeepFilesEmitsRunLeafChanged(): void
    {
        /** @var InProcessAgentSessionClient $client */
        $client = self::getContainer()->get(InProcessAgentSessionClient::class);
        /** @var InMemoryRuntimeEventSink $sink */
        $sink = self::getContainer()->get(InMemoryRuntimeEventSink::class);

        $client->send(self::RUN_ID, new UserCommand(
            type: 'tree_navigate_to_turn',
            payload: ['turn_no' => 1, 'file_choice' => 'keep_files'],
        ));

        $events = iterator_to_array($sink->drain(self::RUN_ID));
        self::assertCount(1, $events);
        self::assertSame(RuntimeEventTypeEnum::RunLeafChanged->value, $events[0]->type);
        self::assertSame(1, $events[0]->payload['turn_no'] ?? null);
        self::assertSame(42, $events[0]->payload['leaf_set_seq'] ?? null);
    }

    #[Test]
    public function sendTreeNavigateRestoreFilesEmitsRunLeafChanged(): void
    {
        /** @var InProcessAgentSessionClient $client */
        $client = self::getContainer()->get(InProcessAgentSessionClient::class);
        /** @var InMemoryRuntimeEventSink $sink */
        $sink = self::getContainer()->get(InMemoryRuntimeEventSink::class);

        $client->send(self::RUN_ID, new UserCommand(
            type: 'tree_navigate_to_turn',
            payload: ['turn_no' => 1, 'file_choice' => 'restore_files'],
        ));

        $events = iterator_to_array($sink->drain(self::RUN_ID));
        self::assertCount(1, $events);
        self::assertSame(RuntimeEventTypeEnum::RunLeafChanged->value, $events[0]->type);
    }

    #[Test]
    public function sendTreeNavigateUndoEmitsFileRewindUndoOkStatus(): void
    {
        /** @var InProcessAgentSessionClient $client */
        $client = self::getContainer()->get(InProcessAgentSessionClient::class);
        /** @var InMemoryRuntimeEventSink $sink */
        $sink = self::getContainer()->get(InMemoryRuntimeEventSink::class);

        $client->send(self::RUN_ID, new UserCommand(
            type: 'tree_navigate_to_turn',
            payload: ['turn_no' => 1, 'file_choice' => 'undo_file_rewind'],
        ));

        $events = iterator_to_array($sink->drain(self::RUN_ID));
        self::assertCount(1, $events);
        self::assertSame(RuntimeEventTypeEnum::StatusUpdated->value, $events[0]->type);
        self::assertSame('file_rewind_undo_ok', $events[0]->payload['status'] ?? null);
    }
}
