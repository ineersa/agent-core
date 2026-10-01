<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\History;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\TestActiveRunContext;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\History\HistoryTailDiscardService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(HistoryTailDiscardService::class)]
final class HistoryTailDiscardServiceTest extends TestCase
{
    /**
     * Thesis: mutate-behind-tip must append history_tail_discarded so forward turns
     * leave active history; without it, abandoned future stays selectable/replayable.
     */
    public function testDiscardForwardTailWhenBehindTip(): void
    {
        $runId = 'discard-test';
        $events = [
            $this->event($runId, 1, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            $this->event($runId, 2, 1, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 1]),
            $this->event($runId, 3, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2]),
            $this->event($runId, 4, 2, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 2]),
            $this->event($runId, 5, 1, RunEventTypeEnum::HistoryPositionSet->value, [
                'position_turn_no' => 1,
                'reason' => 'history_select',
            ]),
        ];

        $appended = null;
        $order = [];
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects($this->once())
            ->method('append')
            ->willReturnCallback(static function (RunEvent $event) use (&$appended, &$order): RunEvent {
                $order[] = 'append';
                $appended = $event;

                return new RunEvent(
                    runId: $event->runId,
                    seq: 6,
                    turnNo: $event->turnNo,
                    type: $event->type,
                    payload: $event->payload,
                    createdAt: $event->createdAt,
                );
            });

        $projectionStore = new InMemoryHistoryProjectionStore();
        $projectionStore->seedFromEvents($runId, $events);
        $innerActive = new TestActiveRunContext();
        $active = new class($innerActive, $order) implements \Ineersa\AgentCore\Contract\ActiveRunContextInterface {
            /** @param list<string> $order */
            public function __construct(
                private TestActiveRunContext $inner,
                private array &$order,
            ) {
            }

            public function stateFor(string $runId): RunState
            {
                return $this->inner->stateFor($runId);
            }

            public function remember(RunState $state): void
            {
                $this->inner->remember($state);
            }

            public function initializeQueued(string $runId): RunState
            {
                return $this->inner->initializeQueued($runId);
            }

            public function initialize(RunState $state): void
            {
                $this->inner->initialize($state);
            }

            public function applyCommittedSuffix(string $runId, array $events, callable $advance): RunState
            {
                return $this->inner->applyCommittedSuffix($runId, $events, $advance);
            }

            public function invalidate(string $runId): void
            {
                $this->inner->invalidate($runId);
            }

            public function withdrawForCommit(string $runId): void
            {
                $this->order[] = 'withdraw';
                $this->inner->withdrawForCommit($runId);
            }

            public function clear(): void
            {
                $this->inner->clear();
            }
        };
        $service = new HistoryTailDiscardService(
            $store,
            $projectionStore,
            $this->sessionStore(),
            new NullLogger(),
            $active,
            new RunLockManager(new LockFactory(new InMemoryStore())),
        );
        $state = new RunState(
            runId: $runId,
            status: RunStatus::Completed,
            version: 1,
            turnNo: 1,
            lastSeq: 5,
        );

        $result = $service->discardForwardTailIfNeeded($runId, $state);

        $this->assertTrue($result['discarded']);
        $this->assertSame(6, $result['lastSeq']);
        $this->assertSame(['withdraw', 'append'], $order);
        $this->assertTrue($projectionStore->get($runId)->ready);
        $this->assertInstanceOf(RunEvent::class, $appended);
        $this->assertSame(RunEventTypeEnum::HistoryTailDiscarded->value, $appended->type);
        $this->assertSame(1, $appended->payload['after_turn_no']);
    }

    public function testNoDiscardWhenAtTip(): void
    {
        $runId = 'discard-tip';
        $events = [
            $this->event($runId, 1, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            $this->event($runId, 2, 1, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 1]),
        ];

        $store = $this->createMock(EventStoreInterface::class);
        $store->expects($this->never())->method('append');

        $projectionStore = new InMemoryHistoryProjectionStore();
        $projectionStore->seedFromEvents($runId, $events);
        $service = new HistoryTailDiscardService(
            $store,
            $projectionStore,
            $this->sessionStore(),
            new NullLogger(),
            new TestActiveRunContext(),
            new RunLockManager(new LockFactory(new InMemoryStore())),
        );
        $state = new RunState(
            runId: $runId,
            status: RunStatus::Completed,
            version: 1,
            turnNo: 1,
            lastSeq: 2,
        );

        $result = $service->discardForwardTailIfNeeded($runId, $state);
        $this->assertFalse($result['discarded']);
        $this->assertSame(2, $result['lastSeq']);
    }

    public function testDetectsMutatingMessages(): void
    {
        $service = new HistoryTailDiscardService(
            $this->createStub(EventStoreInterface::class),
            new InMemoryHistoryProjectionStore(),
            $this->sessionStore(),
            new NullLogger(),
            new TestActiveRunContext(),
            new RunLockManager(new LockFactory(new InMemoryStore())),
        );

        $this->assertTrue($service->isContextMutatingMessage(new AdvanceRun(
            runId: 'r',
            turnNo: 1,
            stepId: 's',
            attempt: 1,
            idempotencyKey: 'k',
        )));
        $this->assertTrue($service->isContextMutatingMessage(new ApplyCommand(
            runId: 'r',
            turnNo: 1,
            stepId: 's',
            attempt: 1,
            idempotencyKey: 'k',
            kind: 'follow_up',
            payload: ['text' => 'x'],
        )));
        $this->assertFalse($service->isContextMutatingMessage(new ApplyCommand(
            runId: 'r',
            turnNo: 1,
            stepId: 's',
            attempt: 1,
            idempotencyKey: 'k',
            kind: 'select_history_turn',
            payload: [],
        )));
    }

    /**
     * Structural tests use non-numeric run ids, so
     * {@see HatfieldSessionStore::resetReasoningBaseline()} is a no-op.
     */
    private function sessionStore(): HatfieldSessionStore
    {
        return new HatfieldSessionStore(
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: '/tmp',
            ),
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
            dispatcher: new EventDispatcher(),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(string $runId, int $seq, int $turnNo, string $type, array $payload): RunEvent
    {
        return new RunEvent(
            runId: $runId,
            seq: $seq,
            turnNo: $turnNo,
            type: $type,
            payload: $payload,
        );
    }
}
