<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\Batch\Deferred;

use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Event\DeferredToolCompletionRegisteredEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchChildTurnHookSubscriber;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchLifecycleDeliveryService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchProgressDeliveryService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchTerminalCompletionService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredToolCompletionRegisteredBatchListener;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeliverDeferredSubagentBatchLifecycleMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\ObserveDeferredSubagentBatchChildTurnHandler;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\ObserveDeferredSubagentBatchChildTurnMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunEventProjector;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Progress\SubagentProgressEventAppender;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\SubagentChildRunBatchLifecycleListener;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder;
use Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Session\CommittedRunEventAppender;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('db')]
final class DeferredSubagentBatchLifecycleTest extends IsolatedKernelTestCase
{
    public function testObservationProjectsChildCursorAndAggregateRevisionWithGapAndDuplicateSemantics(): void
    {
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $factory = new DeferredSubagentBatchIdentityFactory();
        $parent = 'parent-batch-obs';
        $tool = 'tool-batch-obs';
        $lifecycle = $factory->batchLifecycleId($parent, $tool);
        $c1 = $factory->childIdentity($parent, $tool, 1);
        $repo->reserveBatch(
            lifecycleId: $lifecycle,
            parentRunId: $parent,
            parentTurnNo: 2,
            parentToolCallId: $tool,
            parentOrderIndex: 0,
            executionMode: ChildRunBatchExecutionModeEnum::Parallel,
            totalChildCount: 1,
            deadlineAt: new \DateTimeImmutable('+600 seconds'),
            childIntents: [[
                'batchIndex' => 1,
                'childRunId' => $c1['childRunId'],
                'artifactId' => $c1['artifactId'],
                'agentName' => 'worker',
                'task' => 'Observe',
                'definitionModel' => null,
            ]],
        );
        $repo->applyLaunchSuccessState($parent, $tool, $lifecycle, new \DateTimeImmutable(), [1]);

        $bus = new TestMessageBus();
        $handler = new ObserveDeferredSubagentBatchChildTurnHandler(
            $repo,
            self::getContainer()->get(\Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository::class),
            new DeferredChildRunEventProjector(),
            new TestLogger(),
            $bus,
        );

        $handler(new ObserveDeferredSubagentBatchChildTurnMessage(
            batchLifecycleId: $lifecycle,
            batchIndex: 1,
            childRunId: $c1['childRunId'],
            committedStatus: RunStatus::Running,
            turnNo: 1,
            committedEvents: [new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, ['usage' => ['input_tokens' => 1]])],
        ));

        $batch = $repo->findByLifecycleId($lifecycle);
        $this->assertNotNull($batch);
        $this->assertSame(1, $batch->aggregateProgressRevision);
        $this->assertSame(1, $batch->children[0]->childEventCursor);
        $this->assertNotNull($batch->children[0]->childLifecycleProjection);
        $this->assertGreaterThanOrEqual(1, \count($bus->messages));

        $bus->messages = [];
        $handler(new ObserveDeferredSubagentBatchChildTurnMessage(
            batchLifecycleId: $lifecycle,
            batchIndex: 1,
            childRunId: $c1['childRunId'],
            committedStatus: RunStatus::Running,
            turnNo: 1,
            committedEvents: [new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, ['usage' => ['input_tokens' => 1]])],
        ));
        $batchDup = $repo->findByLifecycleId($lifecycle);
        $this->assertSame(1, $batchDup->aggregateProgressRevision);

        $logger = new TestLogger();
        $handlerGap = new ObserveDeferredSubagentBatchChildTurnHandler($repo, self::getContainer()->get(\Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository::class), new DeferredChildRunEventProjector(), $logger, new TestMessageBus());
        $handlerGap(new ObserveDeferredSubagentBatchChildTurnMessage(
            batchLifecycleId: $lifecycle,
            batchIndex: 1,
            childRunId: $c1['childRunId'],
            committedStatus: RunStatus::Running,
            turnNo: 2,
            committedEvents: [new AfterTurnCommitEventSummary(3, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed'])],
        ));
        $this->assertContains('deferred_subagent_batch.child_event_gap', array_column($logger->records, 'message'));
        $batchGap = $repo->findByLifecycleId($lifecycle);
        $this->assertSame(1, $batchGap->children[0]->childEventCursor);
        $this->assertSame(1, $batchGap->aggregateProgressRevision);

        $hookBus = new TestMessageBus();
        $hook = new DeferredSubagentBatchChildTurnHookSubscriber(
            self::getContainer()->get(\Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository::class),
            $hookBus,
            new TestLogger(),
        );
        $hook->handleAfterTurnCommit(new AfterTurnCommitHookContext(
            runId: $c1['childRunId'],
            turnNo: 1,
            status: 'running',
            events: [new AfterTurnCommitEventSummary(2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2])],
            effectsCount: 0,
        ));
        $this->assertInstanceOf(ObserveDeferredSubagentBatchChildTurnMessage::class, $hookBus->messages[0]);
    }

    public function testAggregateParallelProgressUsesRevisionDedupAndStatusPrecedence(): void
    {
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $factory = new DeferredSubagentBatchIdentityFactory();
        $parent = 'parent-batch-progress';
        $tool = 'tool-batch-progress';
        $lifecycle = $factory->batchLifecycleId($parent, $tool);
        $c1 = $factory->childIdentity($parent, $tool, 1);
        $c2 = $factory->childIdentity($parent, $tool, 2);
        $repo->reserveBatch(
            lifecycleId: $lifecycle,
            parentRunId: $parent,
            parentTurnNo: 3,
            parentToolCallId: $tool,
            parentOrderIndex: 1,
            executionMode: ChildRunBatchExecutionModeEnum::Parallel,
            totalChildCount: 2,
            deadlineAt: new \DateTimeImmutable('+600 seconds'),
            childIntents: [
                ['batchIndex' => 1, 'childRunId' => $c1['childRunId'], 'artifactId' => $c1['artifactId'], 'agentName' => 'a-one', 'task' => 'One', 'definitionModel' => null],
                ['batchIndex' => 2, 'childRunId' => $c2['childRunId'], 'artifactId' => $c2['artifactId'], 'agentName' => 'a-two', 'task' => 'Two', 'definitionModel' => null],
            ],
        );
        $repo->applyLaunchSuccessState($parent, $tool, $lifecycle, new \DateTimeImmutable('-3 seconds'), [1, 2]);

        $observeBus = new TestMessageBus();
        $handler = new ObserveDeferredSubagentBatchChildTurnHandler(
            $repo,
            self::getContainer()->get(\Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository::class),
            new DeferredChildRunEventProjector(),
            new TestLogger(),
            $observeBus,
        );
        $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 1, $c1['childRunId'], RunStatus::Running, 1, [
            new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, ['usage' => ['input_tokens' => 2, 'output_tokens' => 1, 'total_tokens' => 3]]),
        ]));
        $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 2, $c2['childRunId'], RunStatus::Failed, 1, [
            new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepFailed->value, ['error' => ['message' => 'child-two-failed']]),
        ]));

        $batch = $repo->findByLifecycleId($lifecycle);
        $this->assertSame(2, $batch->aggregateProgressRevision);

        $appended = [];
        $appender = new class(self::getContainer()->get(CommittedRunEventAppender::class), $appended) extends SubagentProgressEventAppender {
            /** @param list<array<string, mixed>> $appended */
            public function __construct(CommittedRunEventAppender $inner, private array &$appended)
            {
                parent::__construct($inner);
            }

            public function append(string $parentRunId, int $parentTurnNo, string $parentToolCallId, int $parentOrderIndex, string $toolName, array $progress): \Ineersa\AgentCore\Domain\Event\RunEvent
            {
                $this->appended[] = $progress;

                return parent::append($parentRunId, $parentTurnNo, $parentToolCallId, $parentOrderIndex, $toolName, $progress);
            }
        };

        $progress = new DeferredSubagentBatchProgressDeliveryService(
            $repo,
            self::getContainer()->get(SubagentProgressSnapshotBuilder::class),
            self::getContainer()->get(SubagentChildProgressSummaryBuilder::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchProgressService::class),
            $appender,
            new TestLogger(),
        );

        $batch = $repo->findByLifecycleId($lifecycle);
        $this->assertTrue($progress->deliverIfNeeded($batch));
        $this->assertCount(1, $appended);
        $payload = $appended[0];
        $this->assertSame('parallel', $payload['mode']);
        $this->assertSame('running', $payload['status']);
        $this->assertNotSame('done', $payload['status']);
        $this->assertCount(2, $payload['children']);
        $this->assertSame(1, $payload['children'][0]['index']);
        $this->assertSame(2, $payload['children'][1]['index']);

        $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 1, $c1['childRunId'], RunStatus::Cancelled, 2, [
            new AfterTurnCommitEventSummary(2, RunEventTypeEnum::AgentEnd->value, ['reason' => 'cancelled']),
        ]));
        $batch = $repo->findByLifecycleId($lifecycle);
        $this->assertSame(3, $batch->aggregateProgressRevision);
        $batch = $repo->findByLifecycleId($lifecycle);
        $this->assertTrue($progress->deliverIfNeeded($batch));
        $this->assertSame('failed', $appended[1]['status']);
        $this->assertNotSame('done', $appended[1]['status']);

        $batchAfter = $repo->findByLifecycleId($lifecycle);
        $this->assertSame($batchAfter->aggregateProgressRevision, $batchAfter->deliveredProgressRevision);
        $batchAfter2 = $repo->findByLifecycleId($lifecycle);
        $this->assertFalse($progress->deliverIfNeeded($batchAfter2));
        $this->assertCount(2, $appended);

        // One-child explicit Parallel batch still uses parallel mode (not single).
        $parentSingleParallel = 'parent-one-parallel';
        $toolSingle = 'tool-one-parallel';
        $lifeSp = $factory->batchLifecycleId($parentSingleParallel, $toolSingle);
        $only = $factory->childIdentity($parentSingleParallel, $toolSingle, 1);
        $repo->reserveBatch($lifeSp, $parentSingleParallel, 1, $toolSingle, 0, ChildRunBatchExecutionModeEnum::Parallel, 1, new \DateTimeImmutable('+60 seconds'), [[
            'batchIndex' => 1,
            'childRunId' => $only['childRunId'],
            'artifactId' => $only['artifactId'],
            'agentName' => 'solo',
            'task' => 'Solo parallel',
            'definitionModel' => null,
        ]]);
        $repo->applyLaunchSuccessState($parentSingleParallel, $toolSingle, $lifeSp, new \DateTimeImmutable(), [1]);
        $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifeSp, 1, $only['childRunId'], RunStatus::Running, 1, [
            new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, ['usage' => ['input_tokens' => 1]]),
        ]));
        $appendedSp = [];
        $appenderSp = new class(self::getContainer()->get(CommittedRunEventAppender::class), $appendedSp) extends SubagentProgressEventAppender {
            public function __construct(CommittedRunEventAppender $inner, private array &$appendedSp)
            {
                parent::__construct($inner);
            }

            public function append(string $parentRunId, int $parentTurnNo, string $parentToolCallId, int $parentOrderIndex, string $toolName, array $progress): \Ineersa\AgentCore\Domain\Event\RunEvent
            {
                $this->appendedSp[] = $progress;

                return parent::append($parentRunId, $parentTurnNo, $parentToolCallId, $parentOrderIndex, $toolName, $progress);
            }
        };
        $progressSp = new DeferredSubagentBatchProgressDeliveryService(
            $repo,
            self::getContainer()->get(SubagentProgressSnapshotBuilder::class),
            self::getContainer()->get(SubagentChildProgressSummaryBuilder::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchProgressService::class),
            $appenderSp,
            new TestLogger(),
        );
        $batchSp = $repo->findByLifecycleId($lifeSp);
        $progressSp->deliverIfNeeded($batchSp);
        $this->assertSame('parallel', $appendedSp[0]['mode']);
        $this->assertSame('running', $appendedSp[0]['status']);
    }

    public function testTerminalDeliveryRegistrationRaceAndIdempotency(): void
    {
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $factory = new DeferredSubagentBatchIdentityFactory();
        $parent = 'parent-batch-term';
        $tool = 'tool-batch-term';
        $lifecycle = $factory->batchLifecycleId($parent, $tool);
        $c1 = $factory->childIdentity($parent, $tool, 1);
        $c2 = $factory->childIdentity($parent, $tool, 2);
        $repo->reserveBatch(
            lifecycleId: $lifecycle,
            parentRunId: $parent,
            parentTurnNo: 4,
            parentToolCallId: $tool,
            parentOrderIndex: 2,
            executionMode: ChildRunBatchExecutionModeEnum::Parallel,
            totalChildCount: 2,
            deadlineAt: new \DateTimeImmutable('+600 seconds'),
            childIntents: [
                ['batchIndex' => 1, 'childRunId' => $c1['childRunId'], 'artifactId' => $c1['artifactId'], 'agentName' => 't-one', 'task' => 'T1', 'definitionModel' => null],
                ['batchIndex' => 2, 'childRunId' => $c2['childRunId'], 'artifactId' => $c2['artifactId'], 'agentName' => 't-two', 'task' => 'T2', 'definitionModel' => null],
            ],
        );
        $repo->applyLaunchSuccessState($parent, $tool, $lifecycle, new \DateTimeImmutable(), [1, 2]);
        foreach ([[$c1, 'done-one'], [$c2, 'done-two']] as [$child, $text]) {
            $this->ensureArtifactReserved($parent, $child['childRunId'], $child['artifactId'], 'worker', 'task');
        }

        $handler = new ObserveDeferredSubagentBatchChildTurnHandler(
            $repo,
            self::getContainer()->get(\Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository::class),
            new DeferredChildRunEventProjector(),
            new TestLogger(),
            new TestMessageBus(),
        );
        $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 1, $c1['childRunId'], RunStatus::Completed, 2, [
            new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, ['assistant_message' => ['content' => [['type' => 'text', 'text' => 'done-one']]]]),
            new AfterTurnCommitEventSummary(2, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']),
        ]));

        $commandBus = new TestMessageBus();
        $delivery = $this->buildDeliveryService($commandBus);
        $delivery->deliver($lifecycle);
        $this->assertCount(0, $commandBus->messages);

        $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 2, $c2['childRunId'], RunStatus::Completed, 2, [
            new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, ['assistant_message' => ['content' => [['type' => 'text', 'text' => 'done-two']]]]),
            new AfterTurnCommitEventSummary(2, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']),
        ]));

        $delivery->deliver($lifecycle);
        $this->assertCount(0, $commandBus->messages);

        $deferred = self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class);
        $deferred->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: $lifecycle,
            runId: $parent,
            turnNo: 4,
            stepId: 'turn-4-tools-1',
            attempt: 1,
            idempotencyKey: 'idem-batch',
            toolCallId: $tool,
            toolName: 'subagent',
            arguments: [],
            orderIndex: 2,
        ));

        $regBus = new TestMessageBus();
        (new DeferredToolCompletionRegisteredBatchListener($repo, $regBus))->__invoke(new DeferredToolCompletionRegisteredEvent(new DeferredToolCompletionCorrelation(
            deferredId: $lifecycle,
            runId: $parent,
            turnNo: 4,
            stepId: 'turn-4-tools-1',
            attempt: 1,
            idempotencyKey: 'idem-batch',
            toolCallId: $tool,
            toolName: 'subagent',
            arguments: [],
            orderIndex: 2,
        )));
        $this->assertInstanceOf(DeliverDeferredSubagentBatchLifecycleMessage::class, $regBus->messages[0]);

        $delivery->deliver($lifecycle);
        $this->assertCount(1, $commandBus->messages);
        $this->assertInstanceOf(CompleteDeferredToolCall::class, $commandBus->messages[0]);
        $this->assertFalse($commandBus->messages[0]->isError);
        $this->assertStringContainsString('Parallel subagents completed', $commandBus->messages[0]->content[0]['text']);

        $delivery->deliver($lifecycle);
        $this->assertCount(1, $commandBus->messages);

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $this->assertSame(AgentArtifactStatusEnum::Completed, $registry->get($parent, $c1['artifactId'])->status);
        $this->assertSame(AgentArtifactStatusEnum::Completed, $registry->get($parent, $c2['artifactId'])->status);
    }

    private function buildDeliveryService(TestMessageBus $commandBus): DeferredSubagentBatchLifecycleDeliveryService
    {
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $progress = new DeferredSubagentBatchProgressDeliveryService(
            $repo,
            self::getContainer()->get(SubagentProgressSnapshotBuilder::class),
            self::getContainer()->get(SubagentChildProgressSummaryBuilder::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchProgressService::class),
            self::getContainer()->get(SubagentProgressEventAppender::class),
            new TestLogger(),
        );
        $terminal = new DeferredSubagentBatchTerminalCompletionService(
            $repo,
            self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class),
            self::getContainer()->get(SubagentChildRunBatchLifecycleListener::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentParallelAggregateResultFormatter::class),
            $commandBus,
            new TestLogger(),
        );

        return new DeferredSubagentBatchLifecycleDeliveryService($repo, $progress, $terminal);
    }

    private function ensureArtifactReserved(string $parentRunId, string $childRunId, string $artifactId, string $agentName, string $task): void
    {
        $lifecycle = self::getContainer()->get(ChildRunArtifactLifecycleService::class);
        $lifecycle->ensureReservedPending(new ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: $childRunId,
            artifactId: $artifactId,
            displayName: $agentName,
            taskSummary: $task,
            definitionModel: null,
            artifactKind: AgentArtifactKindEnum::Subagent,
            batchIndex: 1,
        ));
    }
}
