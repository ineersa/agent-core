<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Event;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Repository\RunRelationshipReaderInterface;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\CodingAgent\Session\Event\RunOperationalProjectionControllerSessionLifecycleListener;
use Ineersa\CodingAgent\Session\History\HistoryDTO;
use Ineersa\CodingAgent\Session\History\HistoryProjectionSnapshot;
use Ineersa\CodingAgent\Session\History\HistoryProjectionStoreInterface;
use Ineersa\CodingAgent\Session\RunState\RunStateStoreInterface;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(RunOperationalProjectionControllerSessionLifecycleListener::class)]
final class RunOperationalProjectionControllerSessionLifecycleListenerTest extends IsolatedKernelTestCase
{
    private RunOperationalProjectionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get('test.run_operational_projection_repository');
    }

    public function testStartingRepublishesRootAndReadyChildOperationalIdentityWithoutArchiveReads(): void
    {
        $this->repository->replace(new RunState('session-a', RunStatus::Running, lastSeq: 3, turnNo: 1));
        $this->repository->replace(new RunState('session-b', RunStatus::Running, lastSeq: 1));
        $this->repository->replace(new RunState('child-a', RunStatus::Running, parentRunId: 'session-a', lastSeq: 2));

        $store = self::getContainer()->get(RunStateStoreInterface::class);
        $store->remember(new RunState('session-a', RunStatus::Running, lastSeq: 3, turnNo: 1));
        $store->remember(new RunState('session-b', RunStatus::Running, lastSeq: 1));
        $store->remember(new RunState('child-a', RunStatus::Running, parentRunId: 'session-a', lastSeq: 2));

        $history = self::getContainer()->get(HistoryProjectionStoreInterface::class);
        $history->remember('session-a', new HistoryProjectionSnapshot(
            history: new HistoryDTO([], [], 1),
            lastSeq: 3,
            ready: true,
        ));
        $history->remember('session-b', new HistoryProjectionSnapshot(
            history: new HistoryDTO([], [], 0),
            lastSeq: 1,
            ready: true,
        ));
        $history->remember('child-a', new HistoryProjectionSnapshot(
            history: new HistoryDTO([], [], 0),
            lastSeq: 2,
            ready: true,
        ));

        $eventStore = self::getContainer()->get(EventStoreInterface::class);
        $beforeLatest = $this->latestCalls($eventStore);
        $beforeRange = $this->rangeCalls($eventStore);

        $listener = self::getContainer()->get(RunOperationalProjectionControllerSessionLifecycleListener::class);
        $listener->onSessionStarting(new ControllerSessionStartingEvent('session-a'));

        $this->assertSame(RunStatus::Running, $this->repository->findOperationalStatus('session-a')?->status);
        $this->assertSame(RunStatus::Running, $this->repository->findOperationalStatus('child-a')?->status);
        $this->assertSame(RunStatus::Running, $this->repository->findOperationalStatus('session-b')?->status);

        $relationship = self::getContainer()->get(RunRelationshipReaderInterface::class);
        $this->assertTrue($relationship->isAgentChild('child-a'));
        $this->assertSame('session-a', $relationship->readParentRunId('child-a'));
        $this->assertFalse($relationship->isAgentChild('session-a'));

        $ownedIds = array_map(
            static fn ($row): string => $row->runId,
            $this->repository->findBy(['ownerSessionId' => 'session-a']),
        );
        sort($ownedIds);
        $this->assertSame(['child-a', 'session-a'], $ownedIds);

        $this->assertSame(3, $store->get('session-a')->lastSeq);
        $this->assertSame(2, $store->get('child-a')->lastSeq);
        $this->assertSame(3, $history->get('session-a')->lastSeq);
        $this->assertTrue($history->get('child-a')->ready);
        $this->assertSame($beforeLatest, $this->latestCalls($eventStore), 'warm ready children must not tip-probe');
        $this->assertSame($beforeRange, $this->rangeCalls($eventStore), 'warm ready children must not scan archive');
    }

    public function testStartingRecoversWithdrawnChildProjectionOnceForWorkerDiscovery(): void
    {
        $eventStore = self::getContainer()->get(EventStoreInterface::class);
        $eventStore->append(new RunEvent(
            runId: 'session-w',
            seq: 0,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: [
                'payload' => [
                    'metadata' => [
                        'session' => ['kind' => 'main'],
                        'model' => 'deepseek/deepseek-v4-flash',
                    ],
                    'messages' => [],
                ],
            ],
        ));
        $eventStore->append(new RunEvent(
            runId: 'child-w',
            seq: 0,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: [
                'payload' => [
                    'metadata' => [
                        'session' => [
                            'kind' => 'agent_child',
                            'parent_run_id' => 'session-w',
                            'agent_name' => 'scout',
                            'artifact_id' => 'agent_child_w',
                            'interactive' => false,
                        ],
                        'model' => 'deepseek/deepseek-v4-flash',
                        'reasoning' => 'medium',
                        'tools_scope' => ['allowed_tools' => ['bash']],
                        'extensions' => [],
                    ],
                    'messages' => [],
                ],
            ],
        ));

        $this->repository->replace(new RunState('session-w', RunStatus::Running, lastSeq: 1, turnNo: 0));
        $this->repository->replace(new RunState('child-w', RunStatus::Running, parentRunId: 'session-w', lastSeq: 1, turnNo: 0));

        $store = self::getContainer()->get(RunStateStoreInterface::class);
        $store->remember(new RunState('session-w', RunStatus::Running, lastSeq: 1, turnNo: 0));
        $store->remember(new RunState('child-w', RunStatus::Running, parentRunId: 'session-w', lastSeq: 1, turnNo: 0));
        $store->withdrawForCommit('child-w');

        $history = self::getContainer()->get(HistoryProjectionStoreInterface::class);
        $history->remember('session-w', new HistoryProjectionSnapshot(
            history: new HistoryDTO([], [], 0),
            lastSeq: 1,
            ready: true,
        ));
        $history->remember('child-w', new HistoryProjectionSnapshot(
            history: new HistoryDTO([], [], 0),
            lastSeq: 1,
            ready: true,
        ));
        $history->withdrawForCommit('child-w');

        $listener = self::getContainer()->get(RunOperationalProjectionControllerSessionLifecycleListener::class);
        $listener->onSessionStarting(new ControllerSessionStartingEvent('session-w'));

        $this->assertTrue($store->isReady('child-w'));
        $this->assertTrue($history->get('child-w')->ready);
        $this->assertSame(RunStatus::Running, $this->repository->findOperationalStatus('child-w')?->status);
        $childOwned = array_values(array_map(
            static fn ($row): string => $row->runId,
            array_filter(
                $this->repository->findBy(['ownerSessionId' => 'session-w']),
                static fn ($row): bool => 'session-w' !== $row->runId,
            ),
        ));
        $this->assertSame(['child-w'], $childOwned);
        $this->assertTrue(self::getContainer()->get(RunRelationshipReaderInterface::class)->isAgentChild('child-w'));
    }

    public function testStartingInitializesSharedProjectionsBeforeExtensionPolicyLookups(): void
    {
        $history = self::getContainer()->get(HistoryProjectionStoreInterface::class);
        $metadata = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\RunStartedMetadataReader::class);

        try {
            $metadata->readAllowedExtensions('session-empty-boot');
            $this->fail('Expected missing history projection before controller attach bootstrap.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('History projection missing for run session-empty-boot', $exception->getMessage());
        }

        $listener = self::getContainer()->get(RunOperationalProjectionControllerSessionLifecycleListener::class);
        $listener->onSessionStarting(new ControllerSessionStartingEvent('session-empty-boot'));

        $this->assertNull($metadata->readAllowedExtensions('session-empty-boot'));
        $this->assertSame(0, $history->get('session-empty-boot')->lastSeq);
        $this->assertTrue($history->get('session-empty-boot')->ready);
        $this->assertSame(RunStatus::Queued, $this->repository->findOperationalStatus('session-empty-boot')?->status);
        $this->assertFalse(self::getContainer()->get(RunRelationshipReaderInterface::class)->isAgentChild('session-empty-boot'));
    }

    private function latestCalls(EventStoreInterface $eventStore): int
    {
        return property_exists($eventStore, 'latestSequenceForCalls')
            ? (int) $eventStore->latestSequenceForCalls
            : 0;
    }

    private function rangeCalls(EventStoreInterface $eventStore): int
    {
        return property_exists($eventStore, 'rangeForCalls')
            ? (int) $eventStore->rangeForCalls
            : 0;
    }
}
