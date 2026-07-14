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
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchParentCancelHookSubscriber;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchProgressDeliveryService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchTerminalCompletionService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredToolCompletionRegisteredBatchListener;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeliverDeferredSubagentBatchLifecycleMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\InterruptDeferredSubagentBatchMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\ObserveDeferredSubagentBatchChildTurnHandler;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\ObserveDeferredSubagentBatchChildTurnMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunEventProjector;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Progress\SubagentProgressEventAppender;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\SubagentChildRunBatchLifecycleListener;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder;
use Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Session\CommittedRunEventAppender;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $batch = $repo->findByLifecycleId($lifecycle);
        $this->assertSame(1, $batch->aggregateProgressRevision);

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
        $this->assertSame(2, $payload['total_count']);
        $this->assertCount(2, $payload['children']);
        $this->assertSame(1, $payload['children'][0]['index']);
        $this->assertSame(2, $payload['children'][1]['index']);
        $this->assertSame('running', $payload['children'][1]['status']);

        $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 2, $c2['childRunId'], RunStatus::Failed, 1, [
            new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepFailed->value, ['error' => ['message' => 'child-two-failed']]),
        ]));
        $batch = $repo->findByLifecycleId($lifecycle);
        $this->assertSame(2, $batch->aggregateProgressRevision);

        $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 1, $c1['childRunId'], RunStatus::Cancelled, 2, [
            new AfterTurnCommitEventSummary(2, RunEventTypeEnum::AgentEnd->value, ['reason' => 'cancelled']),
        ]));
        $batch = $repo->findByLifecycleId($lifecycle);
        $this->assertSame(3, $batch->aggregateProgressRevision);
        $batch = $repo->findByLifecycleId($lifecycle);
        $this->assertTrue($progress->deliverIfNeeded($batch));
        $this->assertSame('failed', $appended[1]['status']);
        $this->assertNotSame('done', $appended[1]['status']);
        $this->assertSame('cancelled', $appended[1]['children'][0]['status']);
        $this->assertNotSame('completed', $appended[1]['children'][0]['status']);

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

    #[DataProvider('terminalDeliveryScenarioProvider')]
    public function testTerminalDeliveryRegistrationRaceAndIdempotency(string $scenario): void
    {
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $factory = new DeferredSubagentBatchIdentityFactory();
        $parent = 'parent-batch-term-'.$scenario;
        $tool = 'tool-batch-term-'.$scenario;
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
        foreach ([$c1, $c2] as $child) {
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

        if ('all_completed' === $scenario) {
            $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 2, $c2['childRunId'], RunStatus::Completed, 2, [
                new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, ['assistant_message' => ['content' => [['type' => 'text', 'text' => 'done-two']]]]),
                new AfterTurnCommitEventSummary(2, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']),
            ]));
        } elseif ('partial_cancelled' === $scenario) {
            $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 2, $c2['childRunId'], RunStatus::Cancelled, 2, [
                new AfterTurnCommitEventSummary(1, RunEventTypeEnum::AgentEnd->value, ['reason' => 'cancelled']),
                new AfterTurnCommitEventSummary(2, RunEventTypeEnum::AgentEnd->value, ['reason' => 'cancelled']),
            ]));
        } else {
            $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 2, $c2['childRunId'], RunStatus::Failed, 2, [
                new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepFailed->value, ['error' => ['message' => 'child-two-boom']]),
                new AfterTurnCommitEventSummary(2, RunEventTypeEnum::AgentEnd->value, ['reason' => 'failed']),
            ]));
        }

        $delivery->deliver($lifecycle);
        $this->assertCount(0, $commandBus->messages);

        $deferred = self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class);
        $deferred->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: $lifecycle,
            runId: $parent,
            turnNo: 4,
            stepId: 'turn-4-tools-1',
            attempt: 1,
            idempotencyKey: 'idem-batch-'.$scenario,
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
            idempotencyKey: 'idem-batch-'.$scenario,
            toolCallId: $tool,
            toolName: 'subagent',
            arguments: [],
            orderIndex: 2,
        )));
        $this->assertInstanceOf(DeliverDeferredSubagentBatchLifecycleMessage::class, $regBus->messages[0]);

        $delivery->deliver($lifecycle);
        $this->assertCount(1, $commandBus->messages);
        $complete = $commandBus->messages[0];
        $this->assertInstanceOf(CompleteDeferredToolCall::class, $complete);

        if ('all_completed' === $scenario) {
            $this->assertFalse($complete->isError);
            $this->assertStringContainsString('Parallel subagents completed', $complete->content[0]['text']);
        } elseif ('partial_failure' === $scenario) {
            $this->assertTrue($complete->isError);
            $text = $complete->content[0]['text'];
            $this->assertStringStartsWith('Parallel subagent execution failed for one or more children.', $text);
            $pos1 = strpos($text, $c1['artifactId']);
            $pos2 = strpos($text, $c2['artifactId']);
            $this->assertNotFalse($pos1);
            $this->assertNotFalse($pos2);
            $this->assertLessThan($pos2, $pos1);
            $this->assertStringContainsString('child-two-boom', $text);
            $this->assertFalse($complete->details['retryable'] ?? true);
            $this->assertFalse($complete->error['retryable'] ?? true);
        } else {
            $this->assertTrue($complete->isError);
            $text = $complete->content[0]['text'];
            $this->assertStringStartsWith('Parallel subagent execution failed for one or more children.', $text);
            $pos1 = strpos($text, $c1['artifactId']);
            $pos2 = strpos($text, $c2['artifactId']);
            $this->assertNotFalse($pos1);
            $this->assertNotFalse($pos2);
            $this->assertLessThan($pos2, $pos1);
            $this->assertStringContainsString('Child run was cancelled.', $text);
            $c2pos = strpos($text, $c2['artifactId']);
            $this->assertNotFalse($c2pos);
            $child2Section = substr($text, $c2pos);
            $this->assertStringContainsString('Child run was cancelled.', $child2Section);
            $this->assertStringNotContainsString('Completed with status completed.', $child2Section);
            $this->assertFalse($complete->details['retryable'] ?? true);
            $this->assertFalse($complete->error['retryable'] ?? true);
        }

        $delivery->deliver($lifecycle);
        $this->assertCount(1, $commandBus->messages);

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $this->assertSame(AgentArtifactStatusEnum::Completed, $registry->get($parent, $c1['artifactId'])->status);
        if ('all_completed' === $scenario) {
            $this->assertSame(AgentArtifactStatusEnum::Completed, $registry->get($parent, $c2['artifactId'])->status);
        } elseif ('partial_failure' === $scenario) {
            $this->assertSame(AgentArtifactStatusEnum::Failed, $registry->get($parent, $c2['artifactId'])->status);
        } else {
            $this->assertSame(AgentArtifactStatusEnum::Cancelled, $registry->get($parent, $c2['artifactId'])->status);
        }
    }

    #[DataProvider('interruptionScenarioProvider')]
    public function testBatchInterruptionProducesCorrectArtifactsReportAndIdempotentCompletion(string $kind, string $scenarioTag): void
    {
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $factory = new DeferredSubagentBatchIdentityFactory();
        $parent = 'parent-batch-int-'.$scenarioTag;
        $tool = 'tool-batch-int-'.$scenarioTag;
        $lifecycle = $factory->batchLifecycleId($parent, $tool);
        $c1 = $factory->childIdentity($parent, $tool, 1);
        $c2 = $factory->childIdentity($parent, $tool, 2);
        $repo->reserveBatch(
            lifecycleId: $lifecycle,
            parentRunId: $parent,
            parentTurnNo: 2,
            parentToolCallId: $tool,
            parentOrderIndex: 0,
            executionMode: ChildRunBatchExecutionModeEnum::Parallel,
            totalChildCount: 2,
            deadlineAt: new \DateTimeImmutable('+1 second'),
            childIntents: [
                ['batchIndex' => 1, 'childRunId' => $c1['childRunId'], 'artifactId' => $c1['artifactId'], 'agentName' => 'i-one', 'task' => 'I1', 'definitionModel' => null],
                ['batchIndex' => 2, 'childRunId' => $c2['childRunId'], 'artifactId' => $c2['artifactId'], 'agentName' => 'i-two', 'task' => 'I2', 'definitionModel' => null],
            ],
        );
        $repo->applyLaunchSuccessState($parent, $tool, $lifecycle, new \DateTimeImmutable('-3 seconds'), [1, 2]);
        foreach ([$c1, $c2] as $child) {
            $this->ensureArtifactReserved($parent, $child['childRunId'], $child['artifactId'], 'worker', 'task');
        }

        // Make child 1 naturally Completed
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

        // Persist interruption
        $intentKind = DeferredSubagentInterruptionKindEnum::from($kind);
        $row = $repo->findEntityByLifecycleId($lifecycle);
        $repo->persistInterruptionIntent($lifecycle, $intentKind, new \DateTimeImmutable(), $row->projectionVersion);

        // Register generic deferred completion
        $deferred = self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class);
        $deferred->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: $lifecycle,
            runId: $parent,
            turnNo: 2,
            stepId: 'turn-2-tools-1',
            attempt: 1,
            idempotencyKey: 'idem-int-'.$scenarioTag,
            toolCallId: $tool,
            toolName: 'subagent',
            arguments: [],
            orderIndex: 0,
        ));

        $commandBus = new TestMessageBus();
        $delivery = $this->buildDeliveryService($commandBus);
        $delivery->deliver($lifecycle);

        $this->assertCount(1, $commandBus->messages);
        $complete = $commandBus->messages[0];
        $this->assertInstanceOf(CompleteDeferredToolCall::class, $complete);
        $this->assertTrue($complete->isError);

        if ('timeout' === $kind) {
            $this->assertStringStartsWith('Parallel subagents timed out after', $complete->content[0]['text']);
            $this->assertArrayNotHasKey('cancelled', $complete->details ?? []);
            $this->assertArrayNotHasKey('cancelled', $complete->error ?? []);
        } else {
            $this->assertStringStartsWith('Parallel subagent tool cancelled by parent run.', $complete->content[0]['text']);
            $this->assertTrue($complete->details['cancelled'] ?? false);
            $this->assertTrue($complete->error['cancelled'] ?? false);
        }

        // Artifact assertions
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $this->assertSame(AgentArtifactStatusEnum::Completed, $registry->get($parent, $c1['artifactId'])->status);
        if ('timeout' === $kind) {
            $this->assertSame(AgentArtifactStatusEnum::Failed, $registry->get($parent, $c2['artifactId'])->status);
        } else {
            $this->assertSame(AgentArtifactStatusEnum::Cancelled, $registry->get($parent, $c2['artifactId'])->status);
        }

        // Idempotent repeat
        $commandBus->messages = [];
        $delivery->deliver($lifecycle);
        $this->assertCount(0, $commandBus->messages);
    }

    public function testRegistrationListenerAndParentCancelHookEnqueueCorrectMessages(): void
    {
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $factory = new DeferredSubagentBatchIdentityFactory();
        $parent = 'parent-batch-hooks';
        $tool = 'tool-batch-hooks';
        $lifecycle = $factory->batchLifecycleId($parent, $tool);
        $c1 = $factory->childIdentity($parent, $tool, 1);
        $repo->reserveBatch(
            lifecycleId: $lifecycle,
            parentRunId: $parent,
            parentTurnNo: 1,
            parentToolCallId: $tool,
            parentOrderIndex: 0,
            executionMode: ChildRunBatchExecutionModeEnum::Parallel,
            totalChildCount: 1,
            deadlineAt: new \DateTimeImmutable('+600 seconds'),
            childIntents: [
                ['batchIndex' => 1, 'childRunId' => $c1['childRunId'], 'artifactId' => $c1['artifactId'], 'agentName' => 'h-one', 'task' => 'H1', 'definitionModel' => null],
            ],
        );
        $repo->applyLaunchSuccessState($parent, $tool, $lifecycle, new \DateTimeImmutable(), [1]);

        // Registration listener dispatches delivery + schedules timeout
        $regBus = new TestMessageBus();
        $listener = new DeferredToolCompletionRegisteredBatchListener($repo, $regBus);
        $listener->__invoke(new DeferredToolCompletionRegisteredEvent(new DeferredToolCompletionCorrelation(
            deferredId: $lifecycle,
            runId: $parent,
            turnNo: 1,
            stepId: 'turn-1-tools-1',
            attempt: 1,
            idempotencyKey: 'idem-hooks',
            toolCallId: $tool,
            toolName: 'subagent',
            arguments: [],
            orderIndex: 0,
        )));
        $this->assertCount(2, $regBus->messages);
        $this->assertInstanceOf(DeliverDeferredSubagentBatchLifecycleMessage::class, $regBus->messages[0]);
        $interruptMsg = $regBus->messages[1];
        $this->assertInstanceOf(InterruptDeferredSubagentBatchMessage::class, $interruptMsg);
        $this->assertSame(DeferredSubagentInterruptionKindEnum::Timeout, $interruptMsg->kind);

        // Persist interruption intent and verify re-dispatch on registration re-fire
        $row = $repo->findEntityByLifecycleId($lifecycle);
        $repo->persistInterruptionIntent($lifecycle, DeferredSubagentInterruptionKindEnum::ParentCancelled, new \DateTimeImmutable(), $row->projectionVersion);

        $regBus2 = new TestMessageBus();
        $listener2 = new DeferredToolCompletionRegisteredBatchListener($repo, $regBus2);
        $listener2->__invoke(new DeferredToolCompletionRegisteredEvent(new DeferredToolCompletionCorrelation(
            deferredId: $lifecycle,
            runId: $parent,
            turnNo: 1,
            stepId: 'turn-1-tools-1',
            attempt: 1,
            idempotencyKey: 'idem-hooks',
            toolCallId: $tool,
            toolName: 'subagent',
            arguments: [],
            orderIndex: 0,
        )));
        $this->assertCount(2, $regBus2->messages);
        $this->assertInstanceOf(DeliverDeferredSubagentBatchLifecycleMessage::class, $regBus2->messages[0]);
        $interruptMsg2 = $regBus2->messages[1];
        $this->assertInstanceOf(InterruptDeferredSubagentBatchMessage::class, $interruptMsg2);
        $this->assertSame(DeferredSubagentInterruptionKindEnum::ParentCancelled, $interruptMsg2->kind);

        // Parent cancel hook dispatches ParentCancelled messages only for Cancelling/Cancelled parent
        $hookBus = new TestMessageBus();
        $hook = new DeferredSubagentBatchParentCancelHookSubscriber($repo, $hookBus);
        $result = $hook->handleAfterTurnCommit(new AfterTurnCommitHookContext(
            runId: $parent,
            turnNo: 1,
            status: 'cancelling',
            events: [],
            effectsCount: 0,
        ));
        $this->assertSame('cancelling', $result->status);
        $this->assertInstanceOf(InterruptDeferredSubagentBatchMessage::class, $hookBus->messages[0]);
        $this->assertSame(DeferredSubagentInterruptionKindEnum::ParentCancelled, $hookBus->messages[0]->kind);

        // Non-cancelling status does not dispatch
        $hookBus2 = new TestMessageBus();
        $hook2 = new DeferredSubagentBatchParentCancelHookSubscriber($repo, $hookBus2);
        $hook2->handleAfterTurnCommit(new AfterTurnCommitHookContext(
            runId: $parent,
            turnNo: 1,
            status: 'running',
            events: [],
            effectsCount: 0,
        ));
        $this->assertCount(0, $hookBus2->messages);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function interruptionScenarioProvider(): array
    {
        return [
            'timeout' => ['timeout', 'to'],
            'parent_cancelled' => ['parent_cancelled', 'pc'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function terminalDeliveryScenarioProvider(): array
    {
        return [
            'all_completed' => ['all_completed'],
            'partial_failure' => ['partial_failure'],
            'partial_cancelled' => ['partial_cancelled'],
        ];
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
            $progress,
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
