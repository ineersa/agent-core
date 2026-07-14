<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\Batch\Deferred;

use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathResolver;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStoreFactory;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchRecoveryService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchRunControlWorkerStartedSubscriber;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeliverDeferredSubagentBatchLifecycleMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\InterruptDeferredSubagentBatchMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\ObserveDeferredSubagentBatchChildTurnHandler;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\ObserveDeferredSubagentBatchChildTurnMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\RecoverDeferredSubagentBatchLifecycleMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunEventProjector;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionAgentArtifactPathResolver;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

#[Group('db')]
final class DeferredSubagentBatchRecoveryTest extends IsolatedKernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TestDirectoryIsolation::createHatfieldTree((string) getcwd(), withSessions: true);
    }

    public function testGapRecoveryReconcilesAllChildrenWithFreshBatchVersionsAndDuplicateNoOp(): void
    {
        /** @var DeferredSubagentBatchRepository $batchRepo */
        $batchRepo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        /** @var DeferredSubagentChildRepository $childRepo */
        $childRepo = self::getContainer()->get(DeferredSubagentChildRepository::class);
        $factory = new DeferredSubagentBatchIdentityFactory();
        $parent = 'parent-batch-gap-'.bin2hex(random_bytes(2));
        $tool = 'tool-batch-gap';
        $lifecycle = $factory->batchLifecycleId($parent, $tool);
        $c1 = $factory->childIdentity($parent, $tool, 1);
        $c2 = $factory->childIdentity($parent, $tool, 2);
        $batchRepo->reserveBatch(
            lifecycleId: $lifecycle,
            parentRunId: $parent,
            parentTurnNo: 1,
            parentToolCallId: $tool,
            parentOrderIndex: 0,
            executionMode: ChildRunBatchExecutionModeEnum::Parallel,
            totalChildCount: 2,
            deadlineAt: new \DateTimeImmutable('+600 seconds'),
            childIntents: [
                ['batchIndex' => 1, 'childRunId' => $c1['childRunId'], 'artifactId' => $c1['artifactId'], 'agentName' => 'g-one', 'task' => 'G1', 'definitionModel' => null],
                ['batchIndex' => 2, 'childRunId' => $c2['childRunId'], 'artifactId' => $c2['artifactId'], 'agentName' => 'g-two', 'task' => 'G2', 'definitionModel' => null],
            ],
        );
        $batchRepo->applyLaunchSuccessState($parent, $tool, $lifecycle, new \DateTimeImmutable(), [1, 2]);

        $child1Entity = $childRepo->findEntityByBatchLifecycleAndIndex($lifecycle, 1);
        $this->assertNotNull($child1Entity);
        $batchRepo->applyBatchChildLifecycleProjection(
            batchLifecycleId: $lifecycle,
            batchIndex: 1,
            projection: DeferredChildRunLifecycleProjectionDTO::fromArray([
                'child_status' => 'running',
                'child_turn_no' => 1,
                'last_committed_seq' => 1,
                'input_tokens' => 1,
            ]),
            childEventCursor: 1,
            expectedChildProjectionVersion: $child1Entity->projectionVersion,
            expectedBatchProjectionVersion: $batchRepo->findByLifecycleId($lifecycle)->projectionVersion,
            bumpAggregateRevision: false,
        );

        $this->writeChildEventLine($parent, $c1['artifactId'], $c1['childRunId'], 2, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']);
        $this->writeChildEventLine($parent, $c2['artifactId'], $c2['childRunId'], 1, RunEventTypeEnum::LlmStepCompleted->value, [
            'assistant_message' => ['content' => [['type' => 'text', 'text' => 'child2']]],
        ]);
        $this->writeChildEventLine($parent, $c2['artifactId'], $c2['childRunId'], 2, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']);

        $bus = new TestMessageBus();
        $observe = new ObserveDeferredSubagentBatchChildTurnHandler(
            $batchRepo,
            $childRepo,
            new DeferredChildRunEventProjector(),
            new TestLogger(),
            $bus,
        );
        $observe(new ObserveDeferredSubagentBatchChildTurnMessage(
            $lifecycle,
            1,
            $c1['childRunId'],
            RunStatus::Running,
            2,
            [new AfterTurnCommitEventSummary(5, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2])],
        ));

        $child1AfterGap = $childRepo->findEntityByBatchLifecycleAndIndex($lifecycle, 1);
        $this->assertSame(1, $child1AfterGap?->childEventCursor);
        $this->assertInstanceOf(RecoverDeferredSubagentBatchLifecycleMessage::class, $bus->messages[0]);

        $batchBeforeRecovery = $batchRepo->findByLifecycleId($lifecycle);
        $this->assertSame(0, $batchBeforeRecovery->aggregateProgressRevision);

        $recoveryBus = new TestMessageBus();
        $recovery = new DeferredSubagentBatchRecoveryService(
            $batchRepo,
            $childRepo,
            self::getContainer()->get(AgentChildRunEventStoreFactory::class),
            new DeferredChildRunEventProjector(),
            $recoveryBus,
            new TestLogger(),
        );
        $recovery->recover($lifecycle);

        $child1Recovered = $childRepo->findEntityByBatchLifecycleAndIndex($lifecycle, 1);
        $child2Recovered = $childRepo->findEntityByBatchLifecycleAndIndex($lifecycle, 2);
        $this->assertSame(2, $child1Recovered?->childEventCursor);
        $this->assertSame('completed', $child1Recovered?->childLifecycleProjection['child_status'] ?? null);
        $this->assertSame(2, $child2Recovered?->childEventCursor);
        $this->assertSame('completed', $child2Recovered?->childLifecycleProjection['child_status'] ?? null);

        $batchAfterRecovery = $batchRepo->findByLifecycleId($lifecycle);
        $this->assertSame(2, $batchAfterRecovery->aggregateProgressRevision);
        $this->assertInstanceOf(DeliverDeferredSubagentBatchLifecycleMessage::class, $recoveryBus->messages[0]);

        $recovery->recover($lifecycle);
        $child1Dup = $childRepo->findEntityByBatchLifecycleAndIndex($lifecycle, 1);
        $child2Dup = $childRepo->findEntityByBatchLifecycleAndIndex($lifecycle, 2);
        $this->assertSame(2, $child1Dup?->childEventCursor);
        $this->assertSame(2, $child2Dup?->childEventCursor);
        $batchDup = $batchRepo->findByLifecycleId($lifecycle);
        $this->assertSame(2, $batchDup->aggregateProgressRevision);
    }

    public function testWorkerStartScopesRecoveryAndResumesDeadlineOrInterruption(): void
    {
        /** @var DeferredSubagentBatchRepository $batchRepo */
        $batchRepo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $factory = new DeferredSubagentBatchIdentityFactory();
        $sessionId = 'parent-batch-worker-'.bin2hex(random_bytes(2));
        $otherParent = 'parent-batch-other-'.bin2hex(random_bytes(2));
        $deadline = new \DateTimeImmutable('+300 seconds');

        $unfinishedTool = 'tool-unfinished';
        $unfinishedLifecycle = $factory->batchLifecycleId($sessionId, $unfinishedTool);
        $u1 = $factory->childIdentity($sessionId, $unfinishedTool, 1);
        $batchRepo->reserveBatch(
            lifecycleId: $unfinishedLifecycle,
            parentRunId: $sessionId,
            parentTurnNo: 1,
            parentToolCallId: $unfinishedTool,
            parentOrderIndex: 0,
            executionMode: ChildRunBatchExecutionModeEnum::Parallel,
            totalChildCount: 1,
            deadlineAt: $deadline,
            childIntents: [
                ['batchIndex' => 1, 'childRunId' => $u1['childRunId'], 'artifactId' => $u1['artifactId'], 'agentName' => 'w-one', 'task' => 'W1', 'definitionModel' => null],
            ],
        );
        $batchRepo->applyLaunchSuccessState($sessionId, $unfinishedTool, $unfinishedLifecycle, new \DateTimeImmutable(), [1]);

        $reservedTool = 'tool-reserved';
        $reservedLifecycle = $factory->batchLifecycleId($sessionId, $reservedTool);
        $r1 = $factory->childIdentity($sessionId, $reservedTool, 1);
        $batchRepo->reserveBatch(
            lifecycleId: $reservedLifecycle,
            parentRunId: $sessionId,
            parentTurnNo: 1,
            parentToolCallId: $reservedTool,
            parentOrderIndex: 1,
            executionMode: ChildRunBatchExecutionModeEnum::Parallel,
            totalChildCount: 1,
            deadlineAt: $deadline,
            childIntents: [
                ['batchIndex' => 1, 'childRunId' => $r1['childRunId'], 'artifactId' => $r1['artifactId'], 'agentName' => 'w-res', 'task' => 'WR', 'definitionModel' => null],
            ],
        );

        $interruptTool = 'tool-interrupt';
        $interruptLifecycle = $factory->batchLifecycleId($sessionId, $interruptTool);
        $i1 = $factory->childIdentity($sessionId, $interruptTool, 1);
        $batchRepo->reserveBatch(
            lifecycleId: $interruptLifecycle,
            parentRunId: $sessionId,
            parentTurnNo: 1,
            parentToolCallId: $interruptTool,
            parentOrderIndex: 2,
            executionMode: ChildRunBatchExecutionModeEnum::Parallel,
            totalChildCount: 1,
            deadlineAt: $deadline,
            childIntents: [
                ['batchIndex' => 1, 'childRunId' => $i1['childRunId'], 'artifactId' => $i1['artifactId'], 'agentName' => 'w-int', 'task' => 'WI', 'definitionModel' => null],
            ],
        );
        $batchRepo->applyLaunchSuccessState($sessionId, $interruptTool, $interruptLifecycle, new \DateTimeImmutable(), [1]);
        $interruptBatch = $batchRepo->findByLifecycleId($interruptLifecycle);
        $batchRepo->persistInterruptionIntent(
            $interruptLifecycle,
            DeferredSubagentInterruptionKindEnum::ParentCancelled,
            new \DateTimeImmutable(),
            $interruptBatch->projectionVersion,
        );

        $otherTool = 'tool-other';
        $otherLifecycle = $factory->batchLifecycleId($otherParent, $otherTool);
        $o1 = $factory->childIdentity($otherParent, $otherTool, 1);
        $batchRepo->reserveBatch(
            lifecycleId: $otherLifecycle,
            parentRunId: $otherParent,
            parentTurnNo: 1,
            parentToolCallId: $otherTool,
            parentOrderIndex: 0,
            executionMode: ChildRunBatchExecutionModeEnum::Parallel,
            totalChildCount: 1,
            deadlineAt: $deadline,
            childIntents: [
                ['batchIndex' => 1, 'childRunId' => $o1['childRunId'], 'artifactId' => $o1['artifactId'], 'agentName' => 'w-oth', 'task' => 'WO', 'definitionModel' => null],
            ],
        );
        $batchRepo->applyLaunchSuccessState($otherParent, $otherTool, $otherLifecycle, new \DateTimeImmutable(), [1]);

        $deferred = self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class);
        $deferred->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: $unfinishedLifecycle,
            runId: $sessionId,
            turnNo: 1,
            stepId: 'turn-1-tools-1',
            attempt: 1,
            idempotencyKey: 'idem-unfinished',
            toolCallId: $unfinishedTool,
            toolName: 'subagent',
            arguments: [],
            orderIndex: 0,
        ));
        $deferred->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: $interruptLifecycle,
            runId: $sessionId,
            turnNo: 1,
            stepId: 'turn-1-tools-1',
            attempt: 1,
            idempotencyKey: 'idem-interrupt',
            toolCallId: $interruptTool,
            toolName: 'subagent',
            arguments: [],
            orderIndex: 2,
        ));

        $wrongTransportBus = new TestMessageBus();
        $wrongTransportSubscriber = new DeferredSubagentBatchRunControlWorkerStartedSubscriber(
            $batchRepo,
            $deferred,
            $wrongTransportBus,
            $sessionId,
            new MockClock(new \DateTimeImmutable('2026-01-01 00:00:00')),
        );
        $wrongWorker = new Worker(['tool' => new InMemoryTransport()], $wrongTransportBus);
        $wrongWorker->getMetadata()->set(['transportNames' => ['tool']]);
        $wrongTransportSubscriber(new WorkerStartedEvent($wrongWorker));
        $this->assertCount(0, $wrongTransportBus->messages);

        $unknownSessionBus = new TestMessageBus();
        $unknownSessionSubscriber = new DeferredSubagentBatchRunControlWorkerStartedSubscriber(
            $batchRepo,
            $deferred,
            $unknownSessionBus,
            'unknown',
            new MockClock(new \DateTimeImmutable('2026-01-01 00:00:00')),
        );
        $unknownWorker = new Worker(['run_control' => new InMemoryTransport()], $unknownSessionBus);
        $unknownWorker->getMetadata()->set(['transportNames' => ['run_control']]);
        $unknownSessionSubscriber(new WorkerStartedEvent($unknownWorker));
        $this->assertCount(0, $unknownSessionBus->messages);

        $stampBus = new StampCapturingMessageBus();
        $clock = new MockClock(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $subscriber = new DeferredSubagentBatchRunControlWorkerStartedSubscriber(
            $batchRepo,
            $deferred,
            $stampBus,
            $sessionId,
            $clock,
        );
        $worker = new Worker(['run_control' => new InMemoryTransport()], $stampBus);
        $worker->getMetadata()->set(['transportNames' => ['run_control']]);
        $subscriber(new WorkerStartedEvent($worker));

        $recoverIds = [];
        $interruptKinds = [];
        foreach ($stampBus->messages as $idx => $message) {
            if ($message instanceof RecoverDeferredSubagentBatchLifecycleMessage) {
                $recoverIds[] = $message->batchLifecycleId;
            }
            if ($message instanceof InterruptDeferredSubagentBatchMessage) {
                $interruptKinds[$message->batchLifecycleId] = $message->kind;
                if (DeferredSubagentInterruptionKindEnum::Timeout === $message->kind) {
                    $this->assertArrayHasKey($idx, $stampBus->stampsByIndex);
                    $delay = $this->extractDelayMs($stampBus->stampsByIndex[$idx]);
                    $this->assertGreaterThan(0, $delay);
                }
            }
        }

        $this->assertContains($unfinishedLifecycle, $recoverIds);
        $this->assertContains($reservedLifecycle, $recoverIds);
        $this->assertContains($interruptLifecycle, $recoverIds);
        $this->assertNotContains($otherLifecycle, $recoverIds);
        $this->assertSame(DeferredSubagentInterruptionKindEnum::ParentCancelled, $interruptKinds[$interruptLifecycle] ?? null);
        $this->assertSame(DeferredSubagentInterruptionKindEnum::Timeout, $interruptKinds[$unfinishedLifecycle] ?? null);
        $this->assertArrayNotHasKey($reservedLifecycle, $interruptKinds);

        $interruptRecoveryBus = new TestMessageBus();
        $interruptRecovery = new DeferredSubagentBatchRecoveryService(
            $batchRepo,
            self::getContainer()->get(DeferredSubagentChildRepository::class),
            self::getContainer()->get(AgentChildRunEventStoreFactory::class),
            new DeferredChildRunEventProjector(),
            $interruptRecoveryBus,
            new TestLogger(),
        );
        $interruptRecovery->recover($interruptLifecycle);
        $this->assertCount(1, $interruptRecoveryBus->messages);
        $this->assertInstanceOf(InterruptDeferredSubagentBatchMessage::class, $interruptRecoveryBus->messages[0]);
        $this->assertSame(DeferredSubagentInterruptionKindEnum::ParentCancelled, $interruptRecoveryBus->messages[0]->kind);
    }

    private function writeChildEventLine(string $parentRunId, string $artifactId, string $childRunId, int $seq, string $type, array $payload): void
    {
        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: new AppConfig(tui: new TuiConfig(theme: 'default'), logging: new LoggingConfig(), cwd: (string) getcwd()),
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
        );
        $resolver = new AgentArtifactPathResolver(new SessionAgentArtifactPathResolver($hatfieldSessionStore));
        $path = $resolver->eventsPath($parentRunId, $artifactId);
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0775, true);
        }
        $line = (new EventPayloadNormalizer())->normalize($childRunId, $seq, 1, $type, $payload);
        file_put_contents($path, json_encode($line, \JSON_THROW_ON_ERROR)."\n", \FILE_APPEND);
    }

    /**
     * @param list<object> $stamps
     */
    private function extractDelayMs(array $stamps): int
    {
        foreach ($stamps as $stamp) {
            if ($stamp instanceof DelayStamp) {
                return $stamp->getDelay();
            }
        }

        return 0;
    }
}

final class StampCapturingMessageBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    /** @var array<int, list<object>> */
    public array $stampsByIndex = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;
        $this->stampsByIndex[\count($this->messages) - 1] = $stamps;

        return new Envelope($message, $stamps);
    }
}
