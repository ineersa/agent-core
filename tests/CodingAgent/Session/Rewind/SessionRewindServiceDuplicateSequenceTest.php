<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Rewind;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\RunStateReplayException;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Contract\TurnTree\TurnTreeNodeSnapshotDTO;
use Ineersa\AgentCore\Contract\TurnTree\TurnTreeProjectorInterface;
use Ineersa\AgentCore\Contract\TurnTree\TurnTreeSnapshotDTO;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\CodingAgent\Session\Rewind\SessionRewindService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class SessionRewindServiceDuplicateSequenceTest extends TestCase
{
    public function testRewindThrowsTypedDuplicateSequenceReplayException(): void
    {
        $runId = 'run-dup-seq';
        $events = [
            new RunEvent($runId, 1, 1, RunEventTypeEnum::RunStarted->value, ['step_id' => 's1']),
            new RunEvent($runId, 1, 1, 'user.message', ['text' => 'hi']),
        ];

        $eventStore = new class($events) implements EventStoreInterface {
            public function __construct(private readonly array $events)
            {
            }

            public function append(RunEvent $event): RunEvent
            {
                throw new \LogicException('not expected');
            }

            public function appendMany(array $events): array
            {
                throw new \LogicException('not expected');
            }

            public function allFor(string $runId): array
            {
                return $this->events;
            }
        };

        $tree = new TurnTreeSnapshotDTO(
            runId: $runId,
            nodesByTurnNo: [1 => new TurnTreeNodeSnapshotDTO(turnNo: 1, parentTurnNo: null)],
            currentLeafTurnNo: 1,
        );

        $turnTreeProjector = $this->createStub(TurnTreeProjectorInterface::class);
        $turnTreeProjector->method('build')->willReturn($tree);

        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(runId: $runId, status: RunStatus::Running, version: 1, turnNo: 1, lastSeq: 1, model: 'test-model'), 0);

        $rebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $rebuilder->expects($this->never())->method('rebuildForLeaf');

        $service = new SessionRewindService(
            eventStore: $eventStore,
            runStateRebuilder: $rebuilder,
            runStore: $runStore,
            lockManager: new RunLockManager(new LockFactory(new FlockStore(sys_get_temp_dir()))),
            logger: new NullLogger(),
            turnTreeProjector: $turnTreeProjector,
            replayEventPreparer: new ReplayEventPreparer(),
        );

        try {
            $service->rewind($runId, 1);
            $this->fail('Expected RunStateReplayException');
        } catch (RunStateReplayException $exception) {
            $this->assertTrue($exception->isDuplicateSequences());
        }
    }
}
