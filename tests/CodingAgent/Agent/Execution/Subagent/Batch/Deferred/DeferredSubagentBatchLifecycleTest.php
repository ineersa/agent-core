<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\Batch\Deferred;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Event\DeferredToolCompletionRegisteredEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchProgressService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchChildOutcomeFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchCompletionDispatcher;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchInterruptionCompletionService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchInterruptionService;
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
use Symfony\Component\Clock\MockClock;

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
            childIntents: [
                ['batchIndex' => 1, 'childRunId' => $c1['childRunId'], 'artifactId' => $c1['artifactId'], 'agentName' => 'o-one', 'task' => 'O1', 'definitionModel' => null],
            ],
        );
        $repo->applyLaunchSuccessState($parent, $tool, $lifecycle, new \DateTimeImmutable(), [1]);

        $handler = new ObserveDeferredSubagentBatchChildTurnHandler(
            $repo,
            self::getContainer()->get(\Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository::class),
            new DeferredChildRunEventProjector(),
            new TestLogger(),
            new TestMessageBus(),
        );

        // First turn: observe committed events, aggregate revision increments
        $batchBefore = $repo->findByLifecycleId($lifecycle);
        $this->assertSame(0, $batchBefore->aggregateProgressRevision);

        $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 1, $c1['childRunId'], RunStatus::Running, 1, [
            new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, ['assistant_message' => ['content' => [['type' => 'text', 'text' => 'hello']]]]),
        ]));

        $batchAfter1 = $repo->findByLifecycleId($lifecycle);
        $this->assertSame(1, $batchAfter1->aggregateProgressRevision);

        // Duplicate seq: suppressed, aggregate revision unchanged
        $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 1, $c1['childRunId'], RunStatus::Running, 1, [
            new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, ['assistant_message' => ['content' => [['type' => 'text', 'text' => 'dup']]]]),
        ]));

        $batchAfter2 = $repo->findByLifecycleId($lifecycle);
        $this->assertSame(1, $batchAfter2->aggregateProgressRevision);

        // Gap (seq jump): logged, aggregate revision unchanged
        $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 1, $c1['childRunId'], RunStatus::Running, 3, [
            new AfterTurnCommitEventSummary(3, RunEventTypeEnum::LlmStepCompleted->value, ['assistant_message' => ['content' => [['type' => 'text', 'text' => 'skip']]]]),
        ]));

        $batchAfter3 = $repo->findByLifecycleId($lifecycle);
        $this->assertSame(1, $batchAfter3->aggregateProgressRevision);
    }

    public function testAggregateParallelProgressUsesRevisionDedupAndStatusPrecedence(): void
    {
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $factory = new DeferredSubagentBatchIdentityFactory();
        $parent = 'parent-batch-prog';
        $tool = 'tool-batch-prog';
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
            deadlineAt: new \DateTimeImmutable('+600 seconds'),
            childIntents: [
                ['batchIndex' => 1, 'childRunId' => $c1['childRunId'], 'artifactId' => $c1['artifactId'], 'agentName' => 'p-one', 'task' => 'P1', 'definitionModel' => null],
                ['batchIndex' => 2, 'childRunId' => $c2['childRunId'], 'artifactId' => $c2['artifactId'], 'agentName' => 'p-two', 'task' => 'P2', 'definitionModel' => null],
            ],
        );
        $repo->applyLaunchSuccessState($parent, $tool, $lifecycle, new \DateTimeImmutable(), [1, 2]);

        $handler = new ObserveDeferredSubagentBatchChildTurnHandler(
            $repo,
            self::getContainer()->get(\Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository::class),
            new DeferredChildRunEventProjector(),
            new TestLogger(),
            new TestMessageBus(),
        );

        // Observe child 1 completed, child 2 running — aggregate should be 'running'
        $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 1, $c1['childRunId'], RunStatus::Completed, 2, [
            new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, ['assistant_message' => ['content' => [['type' => 'text', 'text' => 'done-a']]]]),
            new AfterTurnCommitEventSummary(2, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']),
        ]));
        $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 2, $c2['childRunId'], RunStatus::Running, 1, [
            new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, ['assistant_message' => ['content' => [['type' => 'text', 'text' => 'proc']]]]),
        ]));

        $progressRepo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $batch = $progressRepo->findByLifecycleId($lifecycle);
        $this->assertSame(2, $batch->aggregateProgressRevision);

        // Observer delivers running progress
        $appendedSp = [];
        $spyProgressAppender = new class(self::getContainer()->get(CommittedRunEventAppender::class), $appendedSp) extends SubagentProgressEventAppender {
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

        $progressService = new DeferredSubagentBatchProgressDeliveryService(
            $repo,
            self::getContainer()->get(SubagentProgressSnapshotBuilder::class),
            self::getContainer()->get(SubagentChildProgressSummaryBuilder::class),
            self::getContainer()->get(ChildRunBatchProgressService::class),
            $spyProgressAppender,
            new TestLogger(),
        );

        $progressService->deliverIfNeeded($batch);
        $this->assertCount(1, $appendedSp);
        $this->assertSame('running', $appendedSp[0]['status']);

        // Second delivery with same revision is suppressed
        $appendedSp = [];
        $batchAfter = $repo->findByLifecycleId($lifecycle);
        $progressService->deliverIfNeeded($batchAfter);
        $this->assertCount(0, $appendedSp);

        // Verify delivered_revision is in sync
        $batchFinal = $repo->findByLifecycleId($lifecycle);
        $this->assertSame($batchFinal->aggregateProgressRevision, $batchFinal->deliveredProgressRevision);
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
            parentTurnNo: 2,
            parentToolCallId: $tool,
            parentOrderIndex: 0,
            executionMode: ChildRunBatchExecutionModeEnum::Parallel,
            totalChildCount: 2,
            deadlineAt: new \DateTimeImmutable('+600 seconds'),
            childIntents: [
                ['batchIndex' => 1, 'childRunId' => $c1['childRunId'], 'artifactId' => $c1['artifactId'], 'agentName' => \sprintf('t%s-one', $scenario), 'task' => \sprintf('T%s-1', $scenario), 'definitionModel' => null],
                ['batchIndex' => 2, 'childRunId' => $c2['childRunId'], 'artifactId' => $c2['artifactId'], 'agentName' => \sprintf('t%s-two', $scenario), 'task' => \sprintf('T%s-2', $scenario), 'definitionModel' => null],
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

        // Observe child 1 completed
        $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 1, $c1['childRunId'], RunStatus::Completed, 2, [
            new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, ['assistant_message' => ['content' => [['type' => 'text', 'text' => 'done']]]]),
            new AfterTurnCommitEventSummary(2, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']),
        ]));

        // Child 2 status depends on scenario
        if ('partial_cancelled' === $scenario) {
            $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 2, $c2['childRunId'], RunStatus::Cancelled, 2, [
                new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, ['assistant_message' => ['content' => [['type' => 'text', 'text' => 'canc']]]]),
                new AfterTurnCommitEventSummary(2, RunEventTypeEnum::AgentEnd->value, ['reason' => 'cancelled']),
            ]));
        } elseif ('partial_failure' === $scenario) {
            $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 2, $c2['childRunId'], RunStatus::Failed, 2, [
                new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, ['assistant_message' => ['content' => [['type' => 'text', 'text' => 'fail']]]]),
                new AfterTurnCommitEventSummary(2, RunEventTypeEnum::AgentEnd->value, ['reason' => 'error', 'status' => 'failed', 'error' => ['message' => 'Bad things']]),
            ]));
        } else {
            $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 2, $c2['childRunId'], RunStatus::Completed, 2, [
                new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, ['assistant_message' => ['content' => [['type' => 'text', 'text' => 'done2']]]]),
                new AfterTurnCommitEventSummary(2, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']),
            ]));
        }

        $deferred = self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class);
        $deferred->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: $lifecycle,
            runId: $parent,
            turnNo: 2,
            stepId: 'turn-2-tools-1',
            attempt: 1,
            idempotencyKey: 'idem-term-'.$scenario,
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

        if ('all_completed' === $scenario) {
            $this->assertFalse($complete->isError);
        } else {
            $this->assertTrue($complete->isError);
            $this->assertStringContainsString('Parallel subagent execution failed', $complete->content[0]['text']);
        }

        // Idempotent repeat
        $commandBus->messages = [];
        $delivery->deliver($lifecycle);
        $this->assertCount(0, $commandBus->messages);
    }

    #[DataProvider('interruptionScenarioProvider')]
    public function testBatchInterruptionProducesCorrectArtifactsReportAndIdempotentCompletion(string $kind, string $scenarioTag): void
    {
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $factory = new DeferredSubagentBatchIdentityFactory();
        $parent = 'parent-batch-int-v2-'.$scenarioTag;
        $tool = 'tool-batch-int-v2-'.$scenarioTag;
        $lifecycle = $factory->batchLifecycleId($parent, $tool);
        $c1 = $factory->childIdentity($parent, $tool, 1);
        $c2 = $factory->childIdentity($parent, $tool, 2);
        $deadline = new \DateTimeImmutable('+5 seconds');
        $startedAt = new \DateTimeImmutable('-3 seconds');

        $repo->reserveBatch(
            lifecycleId: $lifecycle,
            parentRunId: $parent,
            parentTurnNo: 2,
            parentToolCallId: $tool,
            parentOrderIndex: 0,
            executionMode: ChildRunBatchExecutionModeEnum::Parallel,
            totalChildCount: 2,
            deadlineAt: $deadline,
            childIntents: [
                ['batchIndex' => 1, 'childRunId' => $c1['childRunId'], 'artifactId' => $c1['artifactId'], 'agentName' => 'i-one', 'task' => 'I1', 'definitionModel' => null],
                ['batchIndex' => 2, 'childRunId' => $c2['childRunId'], 'artifactId' => $c2['artifactId'], 'agentName' => 'i-two', 'task' => 'I2', 'definitionModel' => null],
            ],
        );
        $repo->applyLaunchSuccessState($parent, $tool, $lifecycle, $startedAt, [1, 2]);
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

        // Test-local recording AgentRunner
        $cancelCalls = [];
        $agentRunner = new class($cancelCalls) implements AgentRunnerInterface {
            public function __construct(private array &$calls)
            {
            }

            public function start(StartRunInput $input): string
            {
                throw new \RuntimeException('not used');
            }

            public function continue(string $runId): void
            {
                throw new \RuntimeException('not used');
            }

            public function steer(string $runId, AgentMessage $message): void
            {
                throw new \RuntimeException('not used');
            }

            public function followUp(string $runId, AgentMessage $message): void
            {
                throw new \RuntimeException('not used');
            }

            public function appendMessage(string $runId, AgentMessage $message): void
            {
                throw new \RuntimeException('not used');
            }

            public function cancel(string $runId, ?string $reason = null): void
            {
                $this->calls[] = ['runId' => $runId, 'reason' => $reason];
            }

            public function answerHuman(string $runId, string $questionId, mixed $answer): void
            {
                throw new \RuntimeException('not used');
            }

            public function compact(string $runId, ?string $customInstructions = null): void
            {
                throw new \RuntimeException('not used');
            }
        };

        // MockClock: "now" is well past the 5s deadline so timeout fires immediately
        $mockClock = new MockClock((new \DateTimeImmutable())->modify('+10 seconds'));

        // Build the interruption service and delivery chain
        $commandBus = new TestMessageBus();
        $delivery = $this->buildDeliveryService($commandBus);
        $lifecycleDelivery = $this->buildLifecycleDelivery($commandBus);

        $intentKind = DeferredSubagentInterruptionKindEnum::from($kind);
        // STEP 1: Interrupt BEFORE generic registration — persists intent but performs no cancel/complete
        $interruptionService = new DeferredSubagentBatchInterruptionService(
            $repo,
            $lifecycleDelivery,
            $agentRunner,
            self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildRunBatchLifecyclePolicyFactory::class),
            $commandBus,
            new TestLogger(),
            $mockClock,
        );
        $interruptionService->interrupt($lifecycle, $intentKind);

        // After first interrupt before registration: intent persisted, no cancel yet, no completion
        $this->assertCount(0, $cancelCalls, 'No cancel before generic registration');
        $this->assertCount(0, $commandBus->messages, 'No CompleteDeferredToolCall before registration');
        $batch = $repo->findByLifecycleId($lifecycle);
        $this->assertNotNull($batch->interruptionKind, 'First-wins interruption kind persisted');
        $this->assertSame($intentKind, $batch->interruptionKind);

        // STEP 1b: Invoke OPPOSITE kind BEFORE registration — proves first-wins survives, no cancel since no reg
        $oppositeKind = DeferredSubagentInterruptionKindEnum::Timeout === $intentKind
            ? DeferredSubagentInterruptionKindEnum::ParentCancelled
            : DeferredSubagentInterruptionKindEnum::Timeout;
        $interruptionService->interrupt($lifecycle, $oppositeKind);

        $batch = $repo->findByLifecycleId($lifecycle);
        $this->assertSame($intentKind, $batch->interruptionKind, 'First-wins intent unchanged after opposite kind');
        $this->assertCount(0, $cancelCalls, 'No cancel before registration');

        // STEP 2: Register generic deferred completion
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

        // STEP 3: Now with registration, invoke the ORIGINAL kind — cancels children and completes
        $appendedProgress = [];
        $spyProgressAppender2 = new class(self::getContainer()->get(CommittedRunEventAppender::class), $appendedProgress) extends SubagentProgressEventAppender {
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
        $progressDelivery = new DeferredSubagentBatchProgressDeliveryService(
            $repo,
            self::getContainer()->get(SubagentProgressSnapshotBuilder::class),
            self::getContainer()->get(SubagentChildProgressSummaryBuilder::class),
            self::getContainer()->get(ChildRunBatchProgressService::class),
            $spyProgressAppender2,
            new TestLogger(),
        );
        $completionDispatcher = new DeferredSubagentBatchCompletionDispatcher(
            self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class),
            $repo,
            $commandBus,
            new TestLogger(),
        );
        $outcomeFactoryForInt = new DeferredSubagentBatchChildOutcomeFactory();
        $interruptionCompletion2 = new DeferredSubagentBatchInterruptionCompletionService(
            $repo,
            self::getContainer()->get(SubagentChildRunBatchLifecycleListener::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentParallelAggregateResultFormatter::class),
            $progressDelivery,
            $completionDispatcher,
            $outcomeFactoryForInt,
        );
        $interruptionService2 = new DeferredSubagentBatchInterruptionService(
            $repo,
            new DeferredSubagentBatchLifecycleDeliveryService(
                $repo,
                $progressDelivery,
                self::getContainer()->get(DeferredSubagentBatchTerminalCompletionService::class),
                $interruptionCompletion2,
            ),
            $agentRunner,
            self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildRunBatchLifecyclePolicyFactory::class),
            $commandBus,
            new TestLogger(),
            $mockClock,
        );
        $interruptionService2->interrupt($lifecycle, $intentKind);

        // Only child 2 (active, not completed) should be cancelled
        $this->assertCount(1, $cancelCalls, 'Only active child cancelled');
        $this->assertSame($c2['childRunId'], $cancelCalls[0]['runId']);

        if (DeferredSubagentInterruptionKindEnum::Timeout === $intentKind) {
            $this->assertSame('Parallel subagent timed out.', $cancelCalls[0]['reason']);
        } else {
            $this->assertSame('Parent run cancelled parallel subagent tool.', $cancelCalls[0]['reason']);
        }

        // Verify parent-cancel forced progress vs timeout no progress
        if (DeferredSubagentInterruptionKindEnum::ParentCancelled === $intentKind) {
            $this->assertCount(1, $appendedProgress, 'Parent cancel emits forced progress');
            $payload = $appendedProgress[0];
            $this->assertSame('cancelled', $payload['status']);
            $this->assertCount(2, $payload['children']);
        } else {
            $this->assertCount(0, $appendedProgress, 'Timeout emits no progress');
        }

        // Completion assertion
        $this->assertCount(1, $commandBus->messages);
        $complete = $commandBus->messages[0];
        $this->assertInstanceOf(CompleteDeferredToolCall::class, $complete);
        $this->assertTrue($complete->isError);

        if ('timeout' === $kind) {
            $timeoutSecs = max(1, $deadline->getTimestamp() - $startedAt->getTimestamp()); // 8s = 5 - (-3)
            $this->assertStringStartsWith(\sprintf('Parallel subagents timed out after %d seconds.', $timeoutSecs), $complete->content[0]['text']);
            $this->assertStringContainsString(\sprintf('Timed out after %d seconds.', $timeoutSecs), $complete->content[0]['text']);
            $this->assertArrayNotHasKey('cancelled', $complete->details ?? []);
            $this->assertArrayNotHasKey('cancelled', $complete->error ?? []);
        } else {
            $this->assertStringStartsWith('Parallel subagent tool cancelled by parent run.', $complete->content[0]['text']);
            $this->assertTrue($complete->details['cancelled'] ?? false);
            $this->assertTrue($complete->error['cancelled'] ?? false);
        }

        // Artifact assertions: child 1 naturally Completed, child 2 interrupted
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $this->assertSame(AgentArtifactStatusEnum::Completed, $registry->get($parent, $c1['artifactId'])->status);
        if ('timeout' === $kind) {
            $art2 = $registry->get($parent, $c2['artifactId']);
            $this->assertSame(AgentArtifactStatusEnum::Failed, $art2->status);
            $this->assertSame('Child run timed out.', $art2->failureReason);
        } else {
            $art2 = $registry->get($parent, $c2['artifactId']);
            $this->assertSame(AgentArtifactStatusEnum::Cancelled, $art2->status);
            $this->assertSame('Cancelled by parent run.', $art2->summary);
        }

        // Idempotent repeat: no additional cancel, no second dispatch
        $prevCancelCount = \count($cancelCalls);
        $commandBus->messages = [];
        $interruptionService2->interrupt($lifecycle, $intentKind);
        $this->assertCount($prevCancelCount, $cancelCalls, 'Cancel is not repeated');
        $this->assertCount(0, $commandBus->messages, 'Completion is not re-dispatched');

        // Late Observe after interruption completion is suppressed
        $observeBus = new TestMessageBus();
        $lateHandler = new ObserveDeferredSubagentBatchChildTurnHandler(
            $repo,
            self::getContainer()->get(\Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository::class),
            new DeferredChildRunEventProjector(),
            new TestLogger(),
            $observeBus,
        );
        $lateHandler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 2, $c2['childRunId'], RunStatus::Running, 3, [
            new AfterTurnCommitEventSummary(3, RunEventTypeEnum::LlmStepCompleted->value, ['assistant_message' => ['content' => [['type' => 'text', 'text' => 'late']]]]),
        ]));
        $lateDeliver = array_filter($observeBus->messages, static fn ($m) => $m instanceof DeliverDeferredSubagentBatchLifecycleMessage);
        $this->assertCount(0, $lateDeliver, 'Late observation does not re-enqueue delivery after terminal completion');
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
        return $this->buildLifecycleDelivery($commandBus);
    }

    private function buildLifecycleDelivery(TestMessageBus $commandBus): DeferredSubagentBatchLifecycleDeliveryService
    {
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $progress = new DeferredSubagentBatchProgressDeliveryService(
            $repo,
            self::getContainer()->get(SubagentProgressSnapshotBuilder::class),
            self::getContainer()->get(SubagentChildProgressSummaryBuilder::class),
            self::getContainer()->get(ChildRunBatchProgressService::class),
            self::getContainer()->get(SubagentProgressEventAppender::class),
            new TestLogger(),
        );
        $completionDispatcher = new DeferredSubagentBatchCompletionDispatcher(
            self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class),
            $repo,
            $commandBus,
            new TestLogger(),
        );
        $outcomeFactory = new DeferredSubagentBatchChildOutcomeFactory();
        $naturalCompletion = new DeferredSubagentBatchTerminalCompletionService(
            self::getContainer()->get(SubagentChildRunBatchLifecycleListener::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentParallelAggregateResultFormatter::class),
            $completionDispatcher,
            $outcomeFactory,
        );
        $interruptionCompletion = new DeferredSubagentBatchInterruptionCompletionService(
            $repo,
            self::getContainer()->get(SubagentChildRunBatchLifecycleListener::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentParallelAggregateResultFormatter::class),
            $progress,
            $completionDispatcher,
            $outcomeFactory,
        );

        return new DeferredSubagentBatchLifecycleDeliveryService($repo, $progress, $naturalCompletion, $interruptionCompletion);
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
