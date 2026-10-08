<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\History;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\RunStateDuplicateSequenceReplayException;
use Ineersa\AgentCore\Application\Handler\RunStateReplayException;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\TestActiveRunContext;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Session\History\HistoryProjector;
use Ineersa\CodingAgent\Session\History\HistorySelectionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class HistorySelectionServiceTest extends TestCase
{
    public function testSelectFirstPromptPositionsBeforeItAndReturnsEditorText(): void
    {
        $runId = 'run-history-select-first';
        $events = [
            new RunEvent($runId, 1, 0, RunEventTypeEnum::RunStarted->value, [
                'payload' => ['messages' => [[
                    'role' => 'user',
                    'content' => [['type' => 'text', 'text' => 'First prompt']],
                ]]],
            ]),
            new RunEvent($runId, 2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1, 'step_id' => 'follow_up-1']),
            new RunEvent($runId, 3, 1, RunEventTypeEnum::HistoryPositionSet->value, [
                'position_turn_no' => 1,
                'previous_position_turn_no' => null,
                'reason' => 'continue',
            ]),
            new RunEvent($runId, 4, 1, RunEventTypeEnum::AgentCommandApplied->value, [
                'kind' => 'follow_up',
                'text' => 'Second prompt',
            ]),
            new RunEvent($runId, 5, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2, 'step_id' => 'follow_up-2']),
            new RunEvent($runId, 6, 2, RunEventTypeEnum::HistoryPositionSet->value, [
                'position_turn_no' => 2,
                'previous_position_turn_no' => 1,
                'reason' => 'continue',
            ]),
        ];

        $appended = [];
        $eventStore = new class($events, $appended) implements \Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface {
            /** @var array<string, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO> */
            private array $pending = [];

            /** @param list<RunEvent> $events */
            public function __construct(private array $events, private array &$appended)
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

            public function reverseFor(string $runId): iterable
            {
                return [];
            }

            public function allFor(string $runId): array
            {
                return $this->events;
            }

            public function appendTransition(array $events, array $work): array
            {
                $out = [];
                foreach ($events as $event) {
                    $max = 0;
                    foreach ($this->events as $existing) {
                        $max = max($max, $existing->seq);
                    }
                    $persisted = new RunEvent($event->runId, $max + 1, $event->turnNo, $event->type, $event->payload, $event->createdAt);
                    $this->events[] = $persisted;
                    $this->appended[] = $persisted;
                    $out[] = $persisted;
                }
                $runId = $work['run_id'] ?? ($out[0]->runId ?? null);
                if (!\is_string($runId) || '' === $runId) {
                    throw new \InvalidArgumentException('Prepared transition requires run identity.');
                }
                $this->pending[$runId] = new \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO(
                    hash('sha256', serialize([$work, $out])),
                    0,
                    $work,
                    array_map(static fn (RunEvent $event): int => $event->seq, $out),
                );

                return $out;
            }

            public function assertTransitionReady(string $runId): void
            {
            }

            public function verifiedPendingTransition(string $runId): ?\Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO
            {
                return $this->pending[$runId] ?? null;
            }

            public function verifiedPendingBatch(string $runId, string $identity): array
            {
                $pending = $this->verifiedPendingTransition($runId);
                if (null === $pending || $pending->identity !== $identity) {
                    throw new \RuntimeException('Fixture transition identity mismatch.');
                }

                return [];
            }

            public function finalizeVerifiedTransition(string $runId, string $identity): void
            {
                if (($this->pending[$runId]->identity ?? null) !== $identity) {
                    throw new \RuntimeException('Fixture transition identity mismatch.');
                }
                unset($this->pending[$runId]);
            }
        };

        $activeRunContext = new TestActiveRunContext();
        $activeRunContext->loadRecovered(new RunState(runId: $runId, status: RunStatus::Running, version: 1, turnNo: 2, lastSeq: 6, model: 'test-model'));
        $commandBus = new TestMessageBus();

        $rebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $rebuilder->expects($this->once())
            ->method('rebuildAtPosition')
            ->with($this->anything(), $runId, 0)
            ->willReturn(\Ineersa\AgentCore\Application\Dto\RunStateReplayResult::rebuilt(new RunState(runId: $runId, status: RunStatus::Running)));

        $service = new HistorySelectionService(
            eventStore: $eventStore,
            runStateRebuilder: $rebuilder,
            activeRunContext: $activeRunContext,
            lockManager: new RunLockManager(new LockFactory(new InMemoryStore())),
            logger: new NullLogger(),
            historyProjector: new HistoryProjector(),
            replayEventPreparer: new ReplayEventPreparer(),
            runCommit: new \Ineersa\AgentCore\Application\Pipeline\RunCommit(
                activeRunContext: $activeRunContext,
                eventStore: $eventStore,
                logger: new NullLogger(),
                executionOperations: new \Ineersa\AgentCore\Tests\Support\TestExecutionOperationStore(),
                sourceAcceptance: new \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance(new \Ineersa\AgentCore\Tests\Support\InMemoryCommandStore()),
                finalizer: \Ineersa\AgentCore\Tests\Support\TestTransitionFinalizerFactory::create($eventStore, new \Ineersa\AgentCore\Application\Handler\StepDispatcher(new TestMessageBus(), new TestMessageBus())),
            ),
        );

        $result = $service->selectPrompt($runId, 1, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());
        $this->assertSame(0, $result['rebuiltState']->turnNo);
        $this->assertSame(1, $result['selectedPromptTurnNo']);
        $this->assertSame('First prompt', $result['editorPromptText']);
        $this->assertCount(1, $appended);
        $this->assertSame(RunEventTypeEnum::HistoryPositionSet->value, $appended[0]->type);
        $this->assertSame(0, $appended[0]->payload['position_turn_no']);
        $this->assertSame(1, $appended[0]->payload['selected_prompt_turn_no']);
        $this->assertSame($result['rebuiltState'], $activeRunContext->requireLoaded($runId));
        $this->assertSame([], $commandBus->messages);
    }

    public function testSelectMiddlePromptPositionsAtPredecessorAndReturnsEditorText(): void
    {
        $runId = 'run-history-select-middle';
        $events = [
            new RunEvent($runId, 1, 0, RunEventTypeEnum::RunStarted->value, [
                'payload' => ['messages' => [[
                    'role' => 'user',
                    'content' => [['type' => 'text', 'text' => 'First prompt']],
                ]]],
            ]),
            new RunEvent($runId, 2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1, 'step_id' => 'follow_up-1']),
            new RunEvent($runId, 3, 1, RunEventTypeEnum::HistoryPositionSet->value, [
                'position_turn_no' => 1,
                'previous_position_turn_no' => null,
                'reason' => 'continue',
            ]),
            new RunEvent($runId, 4, 1, RunEventTypeEnum::AgentCommandApplied->value, [
                'kind' => 'follow_up',
                'text' => 'Middle prompt',
            ]),
            new RunEvent($runId, 5, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2, 'step_id' => 'follow_up-2']),
            new RunEvent($runId, 6, 2, RunEventTypeEnum::HistoryPositionSet->value, [
                'position_turn_no' => 2,
                'previous_position_turn_no' => 1,
                'reason' => 'continue',
            ]),
            new RunEvent($runId, 7, 2, RunEventTypeEnum::AgentCommandApplied->value, [
                'kind' => 'follow_up',
                'text' => 'Third prompt',
            ]),
            new RunEvent($runId, 8, 3, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 3, 'step_id' => 'follow_up-3']),
            new RunEvent($runId, 9, 3, RunEventTypeEnum::HistoryPositionSet->value, [
                'position_turn_no' => 3,
                'previous_position_turn_no' => 2,
                'reason' => 'continue',
            ]),
        ];

        $appended = [];
        $eventStore = new class($events, $appended) implements \Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface {
            /** @var array<string, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO> */
            private array $pending = [];

            /** @param list<RunEvent> $events */
            public function __construct(private array $events, private array &$appended)
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

            public function reverseFor(string $runId): iterable
            {
                return [];
            }

            public function allFor(string $runId): array
            {
                return $this->events;
            }

            public function appendTransition(array $events, array $work): array
            {
                $out = [];
                foreach ($events as $event) {
                    $max = 0;
                    foreach ($this->events as $existing) {
                        $max = max($max, $existing->seq);
                    }
                    $persisted = new RunEvent($event->runId, $max + 1, $event->turnNo, $event->type, $event->payload, $event->createdAt);
                    $this->events[] = $persisted;
                    $this->appended[] = $persisted;
                    $out[] = $persisted;
                }
                $runId = $work['run_id'] ?? ($out[0]->runId ?? null);
                if (!\is_string($runId) || '' === $runId) {
                    throw new \InvalidArgumentException('Prepared transition requires run identity.');
                }
                $this->pending[$runId] = new \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO(
                    hash('sha256', serialize([$work, $out])),
                    0,
                    $work,
                    array_map(static fn (RunEvent $event): int => $event->seq, $out),
                );

                return $out;
            }

            public function assertTransitionReady(string $runId): void
            {
            }

            public function verifiedPendingTransition(string $runId): ?\Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO
            {
                return $this->pending[$runId] ?? null;
            }

            public function verifiedPendingBatch(string $runId, string $identity): array
            {
                $pending = $this->verifiedPendingTransition($runId);
                if (null === $pending || $pending->identity !== $identity) {
                    throw new \RuntimeException('Fixture transition identity mismatch.');
                }

                return [];
            }

            public function finalizeVerifiedTransition(string $runId, string $identity): void
            {
                if (($this->pending[$runId]->identity ?? null) !== $identity) {
                    throw new \RuntimeException('Fixture transition identity mismatch.');
                }
                unset($this->pending[$runId]);
            }
        };

        $activeRunContext = new TestActiveRunContext();
        $activeRunContext->loadRecovered(new RunState(runId: $runId, status: RunStatus::Running, version: 1, turnNo: 3, lastSeq: 9, model: 'test-model'));
        $commandBus = new TestMessageBus();

        $rebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $rebuilder->expects($this->once())
            ->method('rebuildAtPosition')
            ->with($this->anything(), $runId, 1)
            ->willReturn(\Ineersa\AgentCore\Application\Dto\RunStateReplayResult::rebuilt(new RunState(runId: $runId, status: RunStatus::Running)));

        $service = new HistorySelectionService(
            eventStore: $eventStore,
            runStateRebuilder: $rebuilder,
            activeRunContext: $activeRunContext,
            lockManager: new RunLockManager(new LockFactory(new InMemoryStore())),
            logger: new NullLogger(),
            historyProjector: new HistoryProjector(),
            replayEventPreparer: new ReplayEventPreparer(),
            runCommit: new \Ineersa\AgentCore\Application\Pipeline\RunCommit(
                activeRunContext: $activeRunContext,
                eventStore: $eventStore,
                logger: new NullLogger(),
                executionOperations: new \Ineersa\AgentCore\Tests\Support\TestExecutionOperationStore(),
                sourceAcceptance: new \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance(new \Ineersa\AgentCore\Tests\Support\InMemoryCommandStore()),
                finalizer: \Ineersa\AgentCore\Tests\Support\TestTransitionFinalizerFactory::create($eventStore, new \Ineersa\AgentCore\Application\Handler\StepDispatcher(new TestMessageBus(), new TestMessageBus())),
            ),
        );

        $result = $service->selectPrompt($runId, 2, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());
        $this->assertSame(1, $result['rebuiltState']->turnNo);
        $this->assertSame(2, $result['selectedPromptTurnNo']);
        $this->assertSame('Middle prompt', $result['editorPromptText']);
        $this->assertCount(1, $appended);
        $this->assertSame(RunEventTypeEnum::HistoryPositionSet->value, $appended[0]->type);
        $this->assertSame(1, $appended[0]->payload['position_turn_no']);
        $this->assertSame(3, $appended[0]->payload['previous_position_turn_no']);
        $this->assertSame(2, $appended[0]->payload['selected_prompt_turn_no']);
        $this->assertSame('history_select', $appended[0]->payload['reason']);
        $this->assertSame($result['rebuiltState'], $activeRunContext->requireLoaded($runId));
        $this->assertSame([], $commandBus->messages);
    }

    public function testSelectInternalRetainedTurnWithoutPromptIsRejected(): void
    {
        $runId = 'run-history-select-internal';
        $events = [
            new RunEvent($runId, 1, 0, RunEventTypeEnum::RunStarted->value, [
                'payload' => ['messages' => [[
                    'role' => 'user',
                    'content' => [['type' => 'text', 'text' => 'First prompt']],
                ]]],
            ]),
            new RunEvent($runId, 2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            // Internal tool-cycle turn: retained, not selectable.
            new RunEvent($runId, 3, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2, 'step_id' => 'advance-after-tools']),
            new RunEvent($runId, 4, 2, RunEventTypeEnum::AgentCommandApplied->value, [
                'kind' => 'follow_up',
                'text' => 'After tools',
            ]),
            new RunEvent($runId, 5, 3, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 3]),
        ];

        $eventStore = new class($events) implements \Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface {
            /** @var array<string, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO> */
            private array $pending = [];

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

            public function reverseFor(string $runId): iterable
            {
                return [];
            }

            public function allFor(string $runId): array
            {
                return $this->events;
            }

            public function appendTransition(array $events, array $work): array
            {
                $runId = $work['run_id'] ?? ($events[0]->runId ?? null);
                if (!\is_string($runId) || '' === $runId) {
                    throw new \InvalidArgumentException('Prepared transition requires run identity.');
                }
                $this->pending[$runId] = new \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO(
                    hash('sha256', serialize([$work, $events])),
                    0,
                    $work,
                    array_map(static fn (RunEvent $event): int => $event->seq, $events),
                );

                return $events;
            }

            public function assertTransitionReady(string $runId): void
            {
            }

            public function verifiedPendingTransition(string $runId): ?\Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO
            {
                return $this->pending[$runId] ?? null;
            }

            public function verifiedPendingBatch(string $runId, string $identity): array
            {
                $pending = $this->verifiedPendingTransition($runId);
                if (null === $pending || $pending->identity !== $identity) {
                    throw new \RuntimeException('Fixture transition identity mismatch.');
                }

                return [];
            }

            public function finalizeVerifiedTransition(string $runId, string $identity): void
            {
                if (($this->pending[$runId]->identity ?? null) !== $identity) {
                    throw new \RuntimeException('Fixture transition identity mismatch.');
                }
                unset($this->pending[$runId]);
            }
        };

        $activeRunContext = new TestActiveRunContext();
        $activeRunContext->loadRecovered(new RunState(runId: $runId, status: RunStatus::Running, version: 1, turnNo: 3, lastSeq: 5, model: 'test-model'));

        $service = new HistorySelectionService(
            eventStore: $eventStore,
            runStateRebuilder: $this->createStub(RunStateRebuilderInterface::class),
            activeRunContext: $activeRunContext,
            lockManager: new RunLockManager(new LockFactory(new InMemoryStore())),
            logger: new NullLogger(),
            historyProjector: new HistoryProjector(),
            replayEventPreparer: new ReplayEventPreparer(),
            runCommit: new \Ineersa\AgentCore\Application\Pipeline\RunCommit(
                activeRunContext: $activeRunContext,
                eventStore: $eventStore,
                logger: new NullLogger(),
                executionOperations: new \Ineersa\AgentCore\Tests\Support\TestExecutionOperationStore(),
                sourceAcceptance: new \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance(new \Ineersa\AgentCore\Tests\Support\InMemoryCommandStore()),
                finalizer: \Ineersa\AgentCore\Tests\Support\TestTransitionFinalizerFactory::create($eventStore, new \Ineersa\AgentCore\Application\Handler\StepDispatcher(new TestMessageBus(), new TestMessageBus())),
            ),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a selectable human prompt');
        $service->selectPrompt($runId, 2, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());
    }

    public function testSelectPromptRejectsDuplicateSequences(): void
    {
        $runId = 'run-history-select-dup';
        $events = [
            new RunEvent($runId, 1, 0, RunEventTypeEnum::RunStarted->value, [
                'payload' => ['messages' => [[
                    'role' => 'user',
                    'content' => [['type' => 'text', 'text' => 'First prompt']],
                ]]],
            ]),
            new RunEvent($runId, 2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1, 'step_id' => 'follow_up-1']),
            new RunEvent($runId, 2, 1, RunEventTypeEnum::HistoryPositionSet->value, [
                'position_turn_no' => 1,
                'reason' => 'continue',
            ]),
        ];

        $eventStore = new class($events) implements \Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface {
            /** @var array<string, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO> */
            private array $pending = [];

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

            public function reverseFor(string $runId): iterable
            {
                return [];
            }

            public function allFor(string $runId): array
            {
                return $this->events;
            }

            public function appendTransition(array $events, array $work): array
            {
                $runId = $work['run_id'] ?? ($events[0]->runId ?? null);
                if (!\is_string($runId) || '' === $runId) {
                    throw new \InvalidArgumentException('Prepared transition requires run identity.');
                }
                $this->pending[$runId] = new \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO(
                    hash('sha256', serialize([$work, $events])),
                    0,
                    $work,
                    array_map(static fn (RunEvent $event): int => $event->seq, $events),
                );

                return $events;
            }

            public function assertTransitionReady(string $runId): void
            {
            }

            public function verifiedPendingTransition(string $runId): ?\Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO
            {
                return $this->pending[$runId] ?? null;
            }

            public function verifiedPendingBatch(string $runId, string $identity): array
            {
                $pending = $this->verifiedPendingTransition($runId);
                if (null === $pending || $pending->identity !== $identity) {
                    throw new \RuntimeException('Fixture transition identity mismatch.');
                }

                return [];
            }

            public function finalizeVerifiedTransition(string $runId, string $identity): void
            {
                if (($this->pending[$runId]->identity ?? null) !== $identity) {
                    throw new \RuntimeException('Fixture transition identity mismatch.');
                }
                unset($this->pending[$runId]);
            }
        };

        $activeRunContext = new TestActiveRunContext();
        $activeRunContext->loadRecovered(new RunState(runId: $runId, status: RunStatus::Running, version: 1, turnNo: 1, lastSeq: 2, model: 'test-model'));

        $rebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $rebuilder->expects($this->never())->method('rebuildAtPosition');

        $service = new HistorySelectionService(
            eventStore: $eventStore,
            runStateRebuilder: $rebuilder,
            activeRunContext: $activeRunContext,
            lockManager: new RunLockManager(new LockFactory(new InMemoryStore())),
            logger: new NullLogger(),
            historyProjector: new HistoryProjector(),
            replayEventPreparer: new ReplayEventPreparer(),
            runCommit: new \Ineersa\AgentCore\Application\Pipeline\RunCommit(
                activeRunContext: $activeRunContext,
                eventStore: $eventStore,
                logger: new NullLogger(),
                executionOperations: new \Ineersa\AgentCore\Tests\Support\TestExecutionOperationStore(),
                sourceAcceptance: new \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance(new \Ineersa\AgentCore\Tests\Support\InMemoryCommandStore()),
                finalizer: \Ineersa\AgentCore\Tests\Support\TestTransitionFinalizerFactory::create($eventStore, new \Ineersa\AgentCore\Application\Handler\StepDispatcher(new TestMessageBus(), new TestMessageBus())),
            ),
        );

        try {
            $service->selectPrompt($runId, 1, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());
            $this->fail('Expected RunStateReplayException');
        } catch (RunStateReplayException $exception) {
            $this->assertInstanceOf(RunStateDuplicateSequenceReplayException::class, $exception);
        }
    }
}
