<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\Batch\Deferred\Lifecycle;

use Ineersa\AgentCore\Application\Handler\CompleteDeferredToolCallHandler;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Event\DeferredToolCompletionRegisteredEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Completion\DeferredSubagentBatchChildOutcomeFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Completion\DeferredSubagentBatchCompletionDispatcher;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Completion\DeferredSubagentBatchTerminalCompletionService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Interruption\DeferredSubagentBatchInterruptionCompletionService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Interruption\DeferredSubagentBatchInterruptionService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Interruption\DeferredSubagentBatchParentCancelHookSubscriber;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Interruption\InterruptDeferredSubagentBatchMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Lifecycle\DeferredSubagentBatchLifecycleDeliveryService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Lifecycle\DeferredToolCompletionRegisteredBatchListener;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Lifecycle\DeliverDeferredSubagentBatchLifecycleMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Observation\ObserveDeferredSubagentBatchChildTurnHandler;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Observation\ObserveDeferredSubagentBatchChildTurnMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Progress\DeferredSubagentBatchProgressDeliveryService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Progress\DeferredSubagentBatchProgressSnapshotFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunEventProjector;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Progress\SubagentProgressEventAppender;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Result\SubagentChildRunHandoffRenderer;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\SubagentChildRunBatchLifecycleListener;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder;
use Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Session\History\RunPresentationReader;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
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
                ['batchIndex' => 1, 'childRunId' => $c1['childRunId'], 'artifactId' => $c1['artifactId'], 'agentName' => 'o-one', 'task' => 'O1', 'launchModel' => 'deepseek/deepseek-v4-flash', 'launchReasoning' => 'medium'],
            ],
        );
        $repo->applyLaunchSuccessState($parent, $tool, $lifecycle, new \DateTimeImmutable(), [1]);

        $handler = new ObserveDeferredSubagentBatchChildTurnHandler(
            $repo,
            self::getContainer()->get(\Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository::class),
            new DeferredChildRunEventProjector(AttributeSerializerValidatorTestFactory::denormalizer(), new \Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer())),
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
                ['batchIndex' => 1, 'childRunId' => $c1['childRunId'], 'artifactId' => $c1['artifactId'], 'agentName' => 'p-one', 'task' => 'P1', 'launchModel' => 'deepseek/deepseek-v4-flash', 'launchReasoning' => 'medium'],
                ['batchIndex' => 2, 'childRunId' => $c2['childRunId'], 'artifactId' => $c2['artifactId'], 'agentName' => 'p-two', 'task' => 'P2', 'launchModel' => 'deepseek/deepseek-v4-flash', 'launchReasoning' => 'medium'],
            ],
        );
        $repo->applyLaunchSuccessState($parent, $tool, $lifecycle, new \DateTimeImmutable(), [1, 2]);

        $handler = new ObserveDeferredSubagentBatchChildTurnHandler(
            $repo,
            self::getContainer()->get(\Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository::class),
            new DeferredChildRunEventProjector(AttributeSerializerValidatorTestFactory::denormalizer(), new \Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer())),
            new TestLogger(),
            new TestMessageBus(),
        );

        // Observe child 1 completed while child 2 is still actively running.
        // Aggregate progress must stay running, revision-deduped, and must not
        // enqueue terminal parent-tool completion until every child is terminal.
        $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 1, $c1['childRunId'], RunStatus::Completed, 2, [
            new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, ['assistant_message' => ['content' => [['type' => 'text', 'text' => 'done-a']]]]),
            new AfterTurnCommitEventSummary(2, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']),
        ]));
        $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 2, $c2['childRunId'], RunStatus::Running, 1, [
            new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, [
                'assistant_message' => ['content' => [['type' => 'text', 'text' => 'still working']]],
            ]),
        ]));

        $progressRepo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $batch = $progressRepo->findByLifecycleId($lifecycle);
        $this->assertSame(2, $batch->aggregateProgressRevision);

        // Observer delivers running progress
        $appendedSp = [];
        $spyProgressAppender = $this->createSpyProgressAppender($appendedSp);

        $progressService = new DeferredSubagentBatchProgressDeliveryService(
            $repo,
            $this->createSnapshotFactory(),
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

        // Verify delivered_revision is in sync and the lifecycle does not enqueue
        // terminal parent-tool completion while a child remains nonterminal.
        $batchFinal = $repo->findByLifecycleId($lifecycle);
        $this->assertSame($batchFinal->aggregateProgressRevision, $batchFinal->deliveredProgressRevision);

        $completionBus = new TestMessageBus();
        $this->buildLifecycleDelivery($completionBus)->deliver($lifecycle);
        $this->assertCount(0, $completionBus->messages);
        $this->assertNull($repo->findByLifecycleId($lifecycle)->terminalCompletionEnqueuedAt);
    }

    #[DataProvider('terminalDeliveryScenarioProvider')]
    public function testTerminalDeliveryRegistrationRaceAndIdempotency(string $scenario): void
    {
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $factory = new DeferredSubagentBatchIdentityFactory();
        $parent = 'parent-batch-term-'.$scenario;
        $tool = 'tool-batch-term-'.$scenario;
        $lifecycle = $factory->batchLifecycleId($parent, $tool);
        $inputStore = self::getContainer()->get(\Ineersa\AgentCore\Contract\Tool\ToolLaunchInputStoreInterface::class);
        $inputStore->publish('fork', $parent, 2, 'turn-2-tools-1', $tool, 'deepseek/deepseek-v4-flash', '', [new AgentMessage('user', [['type' => 'text', 'text' => 'frozen launch']])]);
        $paths = self::getContainer()->get(\Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface::class);
        $inputPath = \dirname($paths->resolveToolBatchesDirectory($parent)).'/tool-launch-inputs/'.hash('sha256', $tool).'.jsonl';
        $this->assertFileExists($inputPath);
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
                ['batchIndex' => 1, 'childRunId' => $c1['childRunId'], 'artifactId' => $c1['artifactId'], 'agentName' => \sprintf('t%s-one', $scenario), 'task' => \sprintf('T%s-1', $scenario), 'launchModel' => 'deepseek/deepseek-v4-flash', 'launchReasoning' => 'medium'],
                ['batchIndex' => 2, 'childRunId' => $c2['childRunId'], 'artifactId' => $c2['artifactId'], 'agentName' => \sprintf('t%s-two', $scenario), 'task' => \sprintf('T%s-2', $scenario), 'launchModel' => 'deepseek/deepseek-v4-flash', 'launchReasoning' => 'medium'],
            ],
        );
        $repo->applyLaunchSuccessState($parent, $tool, $lifecycle, new \DateTimeImmutable(), [1, 2]);
        foreach ([$c1, $c2] as $child) {
            $this->ensureArtifactReserved($parent, $child['childRunId'], $child['artifactId'], 'worker', 'task');
        }

        $handler = new ObserveDeferredSubagentBatchChildTurnHandler(
            $repo,
            self::getContainer()->get(\Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository::class),
            new DeferredChildRunEventProjector(AttributeSerializerValidatorTestFactory::denormalizer(), new \Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer())),
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
        $delivery = $this->buildLifecycleDelivery($commandBus);
        $delivery->deliver($lifecycle);
        $this->assertFileDoesNotExist($inputPath);

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
        $this->assertFileDoesNotExist($inputPath);
        $this->assertCount(0, $commandBus->messages);
    }

    #[DataProvider('interruptionScenarioProvider')]
    public function testBatchInterruptionProducesCorrectArtifactsReportAndIdempotentCompletion(string $kind, string $scenarioTag): void
    {
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $factory = new DeferredSubagentBatchIdentityFactory();
        $parent = 'parent-batch-int-v3-'.$scenarioTag;
        $tool = 'tool-batch-int-v3-'.$scenarioTag;
        $lifecycle = $factory->batchLifecycleId($parent, $tool);
        $c1 = $factory->childIdentity($parent, $tool, 1);
        $c2 = $factory->childIdentity($parent, $tool, 2);
        $deadline = new \DateTimeImmutable('+5 seconds');
        $startedAt = new \DateTimeImmutable('-3 seconds');
        $timeoutSecs = max(1, $deadline->getTimestamp() - $startedAt->getTimestamp());

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
                ['batchIndex' => 1, 'childRunId' => $c1['childRunId'], 'artifactId' => $c1['artifactId'], 'agentName' => 'i-one', 'task' => 'I1', 'launchModel' => 'deepseek/deepseek-v4-flash', 'launchReasoning' => 'medium'],
                ['batchIndex' => 2, 'childRunId' => $c2['childRunId'], 'artifactId' => $c2['artifactId'], 'agentName' => 'i-two', 'task' => 'I2', 'launchModel' => 'deepseek/deepseek-v4-flash', 'launchReasoning' => 'medium'],
            ],
        );
        $repo->applyLaunchSuccessState($parent, $tool, $lifecycle, $startedAt, [1, 2]);
        foreach ([$c1, $c2] as $child) {
            $this->ensureArtifactReserved($parent, $child['childRunId'], $child['artifactId'], 'worker', 'task');
        }

        // Observe child 1 naturally Completed
        $handler = new ObserveDeferredSubagentBatchChildTurnHandler(
            $repo,
            self::getContainer()->get(\Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository::class),
            new DeferredChildRunEventProjector(AttributeSerializerValidatorTestFactory::denormalizer(), new \Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer())),
            new TestLogger(),
            new TestMessageBus(),
        );
        $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 1, $c1['childRunId'], RunStatus::Completed, 2, [
            new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, ['assistant_message' => ['content' => [['type' => 'text', 'text' => 'done-one']]]]),
            new AfterTurnCommitEventSummary(2, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']),
        ]));

        // Observe child 2 as Running with usage data for enrichment/cursor proof
        $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 2, $c2['childRunId'], RunStatus::Running, 3, [
            new AfterTurnCommitEventSummary(3, RunEventTypeEnum::LlmStepCompleted->value, ['assistant_message' => ['content' => [['type' => 'text', 'text' => 'working']]], 'usage' => ['input_tokens' => 50, 'output_tokens' => 30]]),
        ]));

        // Deliver initial running progress BEFORE registration so aggregate==delivered
        $appendedProgress = [];
        $spyAppender = $this->createSpyProgressAppender($appendedProgress);
        $progressDelivery = new DeferredSubagentBatchProgressDeliveryService(
            $repo,
            $this->createSnapshotFactory(),
            $spyAppender,
            new TestLogger(),
        );
        $batchAfterObserve = $repo->findByLifecycleId($lifecycle);
        $this->assertTrue($progressDelivery->deliverIfNeeded($batchAfterObserve), 'Initial running progress emitted');
        $this->assertCount(1, $appendedProgress, 'One running payload before interruption');
        $this->assertSame('running', $appendedProgress[0]['status'], 'Aggregate is running (child 2 unobserved)');
        $this->assertSame(2, $appendedProgress[0]['total_count']);
        $this->assertCount(2, $appendedProgress[0]['children']);

        $batch = $repo->findByLifecycleId($lifecycle);
        $this->assertSame($batch->aggregateProgressRevision, $batch->deliveredProgressRevision, 'Revision equalized before interruption');
        $aggregateRevisionBeforeInterrupt = $batch->aggregateProgressRevision;

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

            public function shell(string $runId, string $rawInput): void
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

        // Build ONE interruption service with the spy-wired lifecycle delivery
        $commandBus = new TestMessageBus();
        $lifecycleDelivery = $this->buildLifecycleDelivery($commandBus, $spyAppender);

        $intentKind = DeferredSubagentInterruptionKindEnum::from($kind);
        // STEP 1: Interrupt BEFORE generic registration — persists intent and cancels launched children; completion still waits for registration
        $interruptionService = new DeferredSubagentBatchInterruptionService(
            $repo,
            $lifecycleDelivery,
            $agentRunner,
            self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchLifecyclePolicyDTO::class),
            $commandBus,
            new TestLogger(),
            $mockClock,
        );
        $interruptionService->interrupt($lifecycle, $intentKind);

        // After first interrupt before registration: intent persisted, launched children cancelled, no completion yet
        $this->assertCount(1, $cancelCalls, 'Launched children cancelled before generic registration');
        $this->assertSame($c2['childRunId'], $cancelCalls[0]['runId']);
        $this->assertCount(0, $commandBus->messages, 'No CompleteDeferredToolCall before registration');
        $batch = $repo->findByLifecycleId($lifecycle);
        $this->assertNotNull($batch->interruptionKind, 'First-wins interruption kind persisted');
        $this->assertSame($intentKind, $batch->interruptionKind);
        $this->assertCount(1, $appendedProgress, 'No extra progress from pre-registration interrupt');

        // STEP 1b: Invoke OPPOSITE kind BEFORE registration — proves first-wins survives, no cancel since no reg
        $oppositeKind = DeferredSubagentInterruptionKindEnum::Timeout === $intentKind
            ? DeferredSubagentInterruptionKindEnum::ParentCancelled
            : DeferredSubagentInterruptionKindEnum::Timeout;
        $interruptionService->interrupt($lifecycle, $oppositeKind);

        $batch = $repo->findByLifecycleId($lifecycle);
        $this->assertSame($intentKind, $batch->interruptionKind, 'First-wins intent unchanged after opposite kind');
        // Pre-registration interrupt re-issues cancel for still-nonterminal launched children.
        $this->assertCount(2, $cancelCalls, 'Opposite kind before registration recancels only the active child');
        $this->assertSame($c2['childRunId'], $cancelCalls[0]['runId']);
        $this->assertSame($c2['childRunId'], $cancelCalls[1]['runId']);
        $this->assertNotContains($c1['childRunId'], array_column($cancelCalls, 'runId'), 'Completed child must not be cancelled');

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

        // STEP 3: Invoke OPPOSITE kind with registration — proves persisted first-wins controls actual behavior
        $interruptionService->interrupt($lifecycle, $oppositeKind);

        // Child 2 remains the only cancelled child; completed child 1 is never cancelled.
        // After registration, cancelStartedChildren may re-issue cancel for the still-active child.
        $this->assertCount(3, $cancelCalls, 'Active child cancelled again after registration; completed child untouched');
        $this->assertSame($c2['childRunId'], $cancelCalls[0]['runId']);
        $this->assertSame($c2['childRunId'], $cancelCalls[1]['runId']);
        $this->assertSame($c2['childRunId'], $cancelCalls[2]['runId']);
        $this->assertNotContains($c1['childRunId'], array_column($cancelCalls, 'runId'));

        if (DeferredSubagentInterruptionKindEnum::Timeout === $intentKind) {
            $this->assertSame('Parallel subagent timed out.', $cancelCalls[0]['reason']);
            $this->assertSame('Parallel subagent timed out.', $cancelCalls[1]['reason']);
            $this->assertSame('Parallel subagent timed out.', $cancelCalls[2]['reason']);
        } else {
            $this->assertSame('Parent run cancelled parallel subagent tool.', $cancelCalls[0]['reason']);
            $this->assertSame('Parent run cancelled parallel subagent tool.', $cancelCalls[1]['reason']);
            $this->assertSame('Parent run cancelled parallel subagent tool.', $cancelCalls[2]['reason']);
        }

        // Verify parent-cancel forced progress vs timeout no extra progress
        if (DeferredSubagentInterruptionKindEnum::ParentCancelled === $intentKind) {
            $this->assertCount(2, $appendedProgress, 'One running + one forced parent-cancel payload');
            $forced = $appendedProgress[1];
            $this->assertSame('cancelled', $forced['status'], 'Aggregate status forced cancelled');
            $this->assertCount(2, $forced['children']);
            $this->assertSame('completed', $forced['children'][0]['status'], 'Child 1 stays completed');
            $this->assertSame('cancelled', $forced['children'][1]['status'], 'Child 2 forced cancelled');
        } else {
            $this->assertCount(1, $appendedProgress, 'Timeout emits no extra progress beyond initial running');
        }

        // Completion assertion
        $this->assertCount(1, $commandBus->messages);
        $complete = $commandBus->messages[0];
        $this->assertInstanceOf(CompleteDeferredToolCall::class, $complete);
        $this->assertTrue($complete->isError);

        if ('timeout' === $kind) {
            $this->assertStringStartsWith(\sprintf('Parallel subagents timed out after %d seconds.', $timeoutSecs), $complete->content[0]['text']);
            $this->assertStringContainsString(\sprintf('Timed out after %ds.', $timeoutSecs), $complete->content[0]['text']);
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
            $this->assertSame(\sprintf('Timed out after %ds.', $timeoutSecs), $art2->summary ?? '');
        } else {
            $art2 = $registry->get($parent, $c2['artifactId']);
            $this->assertSame(AgentArtifactStatusEnum::Cancelled, $art2->status);
            $this->assertSame('Cancelled by parent run.', $art2->summary);
        }

        // Assert ordered artifact IDs in report
        $text = $complete->content[0]['text'];
        $pos1 = strpos($text, $c1['artifactId']);
        $pos2 = strpos($text, $c2['artifactId']);
        $this->assertNotFalse($pos1, 'Child 1 artifact appears in report');
        $this->assertNotFalse($pos2, 'Child 2 artifact appears in report');
        $this->assertLessThan($pos2, $pos1, 'Child 1 before child 2 in report');

        // Interruption progress marker: only set for parent-cancel
        $batchAfterCompletion = $repo->findByLifecycleId($lifecycle);
        if (DeferredSubagentInterruptionKindEnum::ParentCancelled === $intentKind) {
            $this->assertNotNull($batchAfterCompletion->interruptionProgressEnqueuedAt, 'Parent cancel sets progress marker');
        } else {
            $this->assertNull($batchAfterCompletion->interruptionProgressEnqueuedAt, 'Timeout leaves no progress marker');
        }

        // Idempotent repeat: no additional cancel, no second dispatch
        $prevCancelCount = \count($cancelCalls);
        $commandBus->messages = [];
        $interruptionService->interrupt($lifecycle, $oppositeKind);
        $this->assertCount($prevCancelCount, $cancelCalls, 'Cancel is not repeated');
        $this->assertCount(0, $commandBus->messages, 'Completion is not re-dispatched');

        // Late Observe after interruption completion leaves aggregate revision and cursor unchanged
        $batchBeforeLate = $repo->findByLifecycleId($lifecycle);
        $cursorBeforeLateObserve = $batchBeforeLate->aggregateProgressRevision;
        // Capture child2 projection cursor
        $child2BeforeLate = null;
        foreach ($batchBeforeLate->children as $ch) {
            if ($ch->childRunId === $c2['childRunId']) {
                $child2BeforeLate = $ch->childEventCursor;
                break;
            }
        }
        $this->assertNotNull($child2BeforeLate, 'Child 2 has event cursor before late observe');
        $observeBus = new TestMessageBus();
        $lateHandler = new ObserveDeferredSubagentBatchChildTurnHandler(
            $repo,
            self::getContainer()->get(\Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository::class),
            new DeferredChildRunEventProjector(AttributeSerializerValidatorTestFactory::denormalizer(), new \Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer())),
            new TestLogger(),
            $observeBus,
        );
        $lateHandler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 2, $c2['childRunId'], RunStatus::Running, 4, [
            new AfterTurnCommitEventSummary(4, RunEventTypeEnum::LlmStepCompleted->value, ['assistant_message' => ['content' => [['type' => 'text', 'text' => 'late']]]]),
        ]));
        $lateDeliver = array_filter($observeBus->messages, static fn ($m) => $m instanceof DeliverDeferredSubagentBatchLifecycleMessage);
        $this->assertCount(0, $lateDeliver, 'Late observation does not re-enqueue delivery');
        $batchAfterLateObserve = $repo->findByLifecycleId($lifecycle);
        $this->assertSame($cursorBeforeLateObserve, $batchAfterLateObserve->aggregateProgressRevision, 'Aggregate revision unchanged by late observe');
        // Child2 cursor unchanged after late observe
        foreach ($batchAfterLateObserve->children as $ch) {
            if ($ch->childRunId === $c2['childRunId']) {
                $this->assertSame($child2BeforeLate, $ch->childEventCursor, 'Child 2 event cursor unchanged by late observe');
                break;
            }
        }
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
                ['batchIndex' => 1, 'childRunId' => $c1['childRunId'], 'artifactId' => $c1['artifactId'], 'agentName' => 'h-one', 'task' => 'H1', 'launchModel' => 'deepseek/deepseek-v4-flash', 'launchReasoning' => 'medium'],
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
        $hook = new DeferredSubagentBatchParentCancelHookSubscriber($repo);
        $actions = $hook->prepareAfterTurnCommit(new AfterTurnCommitHookContext(
            runId: $parent,
            turnNo: 1,
            status: 'cancelling',
            events: [],
            effectsCount: 0,
            runState: new RunState($parent, RunStatus::Cancelling, turnNo: 1),
        ), 0);
        $this->assertCount(1, $actions);
        $this->assertInstanceOf(InterruptDeferredSubagentBatchMessage::class, $actions[0]->message);
        $this->assertSame(DeferredSubagentInterruptionKindEnum::ParentCancelled, $actions[0]->message->kind);

        // Non-cancelling status does not dispatch
        $hookBus2 = new TestMessageBus();
        $hook2 = new DeferredSubagentBatchParentCancelHookSubscriber($repo);
        $actions2 = $hook2->prepareAfterTurnCommit(new AfterTurnCommitHookContext(
            runId: $parent,
            turnNo: 1,
            status: 'running',
            events: [],
            effectsCount: 0,
            runState: new RunState($parent, RunStatus::Running, turnNo: 1),
        ), 0);
        $this->assertCount(0, $actions2);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    #[DataProvider('singleNaturalTerminalScenarioProvider')]
    public function testSingleBatchNaturalTerminalDeliveryPreservesFlatProgressAndPresentation(string $scenario): void
    {
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $factory = new DeferredSubagentBatchIdentityFactory();
        $parent = 'parent-batch-single-nat-'.$scenario;
        $tool = 'tool-batch-single-nat-'.$scenario;
        $lifecycle = $factory->batchLifecycleId($parent, $tool);
        $inputStore = self::getContainer()->get(\Ineersa\AgentCore\Contract\Tool\ToolLaunchInputStoreInterface::class);
        $inputStore->publish('fork', $parent, 2, 'turn-2-tools-1', $tool, 'deepseek/deepseek-v4-flash', '', [new AgentMessage('user', [['type' => 'text', 'text' => 'frozen launch']])]);
        $paths = self::getContainer()->get(\Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface::class);
        $inputPath = \dirname($paths->resolveToolBatchesDirectory($parent)).'/tool-launch-inputs/'.hash('sha256', $tool).'.jsonl';
        $this->assertFileExists($inputPath);
        $c1 = $factory->childIdentity($parent, $tool, 1);
        $repo->reserveBatch(
            lifecycleId: $lifecycle,
            parentRunId: $parent,
            parentTurnNo: 2,
            parentToolCallId: $tool,
            parentOrderIndex: 0,
            executionMode: ChildRunBatchExecutionModeEnum::Single,
            totalChildCount: 1,
            deadlineAt: new \DateTimeImmutable('+600 seconds'),
            childIntents: [
                ['batchIndex' => 1, 'childRunId' => $c1['childRunId'], 'artifactId' => $c1['artifactId'], 'agentName' => 's-nat', 'task' => 'Do work', 'launchModel' => 'deepseek/deepseek-v4-flash', 'launchReasoning' => 'medium'],
            ],
        );
        $repo->applyLaunchSuccessState($parent, $tool, $lifecycle, new \DateTimeImmutable(), [1]);
        $this->ensureArtifactReserved($parent, $c1['childRunId'], $c1['artifactId'], 's-nat', 'Do work');

        $handler = new ObserveDeferredSubagentBatchChildTurnHandler(
            $repo,
            self::getContainer()->get(\Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository::class),
            new DeferredChildRunEventProjector(AttributeSerializerValidatorTestFactory::denormalizer(), new \Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer())),
            new TestLogger(),
            new TestMessageBus(),
        );

        if ('completed' === $scenario) {
            $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 1, $c1['childRunId'], RunStatus::Completed, 2, [
                new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, [
                    'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'all done']]],
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                new AfterTurnCommitEventSummary(2, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']),
            ]));
        } elseif ('failed' === $scenario) {
            $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 1, $c1['childRunId'], RunStatus::Failed, 2, [
                new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, [
                    'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'oops']]],
                ]),
                new AfterTurnCommitEventSummary(2, RunEventTypeEnum::LlmStepFailed->value, ['error' => ['user_message' => 'boom-msg']]),
            ]));
        } else {
            $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 1, $c1['childRunId'], RunStatus::Cancelled, 2, [
                new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, ['assistant_message' => ['content' => [['type' => 'text', 'text' => 'stop']]]]),
                new AfterTurnCommitEventSummary(2, RunEventTypeEnum::AgentEnd->value, ['reason' => 'cancelled']),
            ]));
        }

        $appended = [];
        $spy = $this->createSpyProgressAppender($appended);
        $commandBus = new TestMessageBus();
        $delivery = $this->buildLifecycleDelivery($commandBus, $spy);
        $batch = $repo->findByLifecycleId($lifecycle);
        $progress = new DeferredSubagentBatchProgressDeliveryService(
            $repo,
            $this->createSnapshotFactory(),
            $spy,
            new TestLogger(),
        );
        $progress->deliverIfNeeded($batch);

        $this->assertCount(1, $appended);
        $payload = $appended[0];
        $this->assertSame('single', $payload['mode']);
        $this->assertSame($c1['artifactId'], $payload['artifact_id']);
        $this->assertSame($c1['childRunId'], $payload['agent_run_id']);
        $this->assertSame('Do work', $payload['task_summary']);
        $this->assertArrayNotHasKey('children', $payload);
        $this->assertArrayNotHasKey('total_count', $payload);
        if ('completed' === $scenario) {
            $this->assertSame('completed', $payload['status']);
            $this->assertArrayHasKey('input_tokens', $payload);
        } elseif ('failed' === $scenario) {
            $this->assertSame('failed', $payload['status']);
        } else {
            $this->assertSame('cancelled', $payload['status']);
        }

        if ('completed' === $scenario) {
            $commandBus->messages = [];
            $delivery->deliver($lifecycle);
            $this->assertFileExists($inputPath);
            $this->assertCount(0, $commandBus->messages, 'No completion before deferred registration');
        }

        $deferred = self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class);
        $deferred->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: $lifecycle,
            runId: $parent,
            turnNo: 2,
            stepId: 'turn-2-tools-1',
            attempt: 1,
            idempotencyKey: 'idem-single-nat-'.$scenario,
            toolCallId: $tool,
            toolName: 'subagent',
            arguments: [],
            orderIndex: 0,
        ));

        $commandBus->messages = [];
        $delivery->deliver($lifecycle);
        $this->assertFileDoesNotExist($inputPath);

        $this->assertCount(1, $commandBus->messages);
        $complete = $commandBus->messages[0];
        $this->assertInstanceOf(CompleteDeferredToolCall::class, $complete);
        $this->assertFalse($complete->isError);
        $this->assertNull($complete->error);
        $this->assertNull($complete->details);
        $presentation = $complete->content[0]['text'];

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $art = $registry->get($parent, $c1['artifactId']);
        $this->assertNotNull($art);
        if ('completed' === $scenario) {
            $this->assertSame(AgentArtifactStatusEnum::Completed, $art->status);
            $this->assertStringStartsWith('Subagent s-nat completed.', $presentation);
            $this->assertStringContainsString('Artifact: '.$c1['artifactId'], $presentation);
            $this->assertStringContainsString("Handoff:\n\nall done", $presentation);
            $this->assertStringNotContainsString('agent_retrieve', $presentation);
            $this->assertStringContainsString('all done', $presentation);
        } elseif ('failed' === $scenario) {
            $this->assertSame(AgentArtifactStatusEnum::Failed, $art->status);
            $this->assertStringStartsWith('Subagent s-nat failed:', $presentation);
            $this->assertStringContainsString('boom-msg', $presentation);
        } else {
            $this->assertSame(AgentArtifactStatusEnum::Cancelled, $art->status);
            $this->assertStringStartsWith('Subagent s-nat was cancelled.', $presentation);
        }

        $commandBus->messages = [];
        $delivery->deliver($lifecycle);
        $this->assertFileDoesNotExist($inputPath);
        $this->assertCount(0, $commandBus->messages);
    }

    #[DataProvider('singleInterruptionScenarioProvider')]
    public function testSingleBatchInterruptionUsesPolicyReasonsForcedProgressAndPresentation(
        string $kind,
        string $tag,
        string $variant,
        bool $expectCancel,
    ): void {
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $factory = new DeferredSubagentBatchIdentityFactory();
        $parent = 'parent-batch-single-int-'.$tag;
        $tool = 'tool-batch-single-int-'.$tag;
        $lifecycle = $factory->batchLifecycleId($parent, $tool);
        $c1 = $factory->childIdentity($parent, $tool, 1);
        $deadline = new \DateTimeImmutable('+5 seconds');
        $startedAt = new \DateTimeImmutable('-3 seconds');
        $timeoutSecs = max(1, $deadline->getTimestamp() - $startedAt->getTimestamp());

        $repo->reserveBatch(
            lifecycleId: $lifecycle,
            parentRunId: $parent,
            parentTurnNo: 2,
            parentToolCallId: $tool,
            parentOrderIndex: 0,
            executionMode: ChildRunBatchExecutionModeEnum::Single,
            totalChildCount: 1,
            deadlineAt: $deadline,
            childIntents: [
                ['batchIndex' => 1, 'childRunId' => $c1['childRunId'], 'artifactId' => $c1['artifactId'], 'agentName' => 's-int', 'task' => 'Interrupt me', 'launchModel' => 'deepseek/deepseek-v4-flash', 'launchReasoning' => 'medium'],
            ],
        );
        $repo->applyLaunchSuccessState($parent, $tool, $lifecycle, $startedAt, [1]);
        $this->ensureArtifactReserved($parent, $c1['childRunId'], $c1['artifactId'], 's-int', 'Interrupt me');

        $handler = new ObserveDeferredSubagentBatchChildTurnHandler(
            $repo,
            self::getContainer()->get(\Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository::class),
            new DeferredChildRunEventProjector(AttributeSerializerValidatorTestFactory::denormalizer(), new \Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer())),
            new TestLogger(),
            new TestMessageBus(),
        );

        if ('no_projection' !== $variant) {
            if ('natural_terminal' !== $variant) {
                $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 1, $c1['childRunId'], RunStatus::Running, 1, [
                    new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, [
                        'assistant_message' => ['content' => [['type' => 'text', 'text' => 'working']]],
                        'usage' => ['input_tokens' => 7, 'output_tokens' => 3],
                    ]),
                ]));
            }
        }

        $appended = [];
        $spy = $this->createSpyProgressAppender($appended);
        $commandBus = new TestMessageBus();
        $lifecycleDelivery = $this->buildLifecycleDelivery($commandBus, $spy);
        $batch = $repo->findByLifecycleId($lifecycle);
        $progressDelivery = new DeferredSubagentBatchProgressDeliveryService($repo, $this->createSnapshotFactory(), $spy, new TestLogger());
        if ('no_projection' !== $variant) {
            $progressDelivery->deliverIfNeeded($batch);
        }

        $cancelCalls = [];
        $agentRunner = new class($cancelCalls) implements AgentRunnerInterface {
            public function __construct(private array &$calls)
            {
            }

            public function start(StartRunInput $input): string
            {
                throw new \RuntimeException('not used');
            }

            public function shell(string $runId, string $rawInput): void
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
        $mockClock = new MockClock((new \DateTimeImmutable())->modify('+10 seconds'));
        $intentKind = DeferredSubagentInterruptionKindEnum::from($kind);
        $interruptionService = new DeferredSubagentBatchInterruptionService(
            $repo,
            $lifecycleDelivery,
            $agentRunner,
            self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchLifecyclePolicyDTO::class),
            $commandBus,
            new TestLogger(),
            $mockClock,
        );

        $interruptionService->interrupt($lifecycle, $intentKind);
        if ('natural_terminal' === $variant) {
            $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 1, $c1['childRunId'], RunStatus::Completed, 2, [
                new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, [
                    'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'natural done']]],
                    'usage' => ['input_tokens' => 4, 'output_tokens' => 2],
                ]),
                new AfterTurnCommitEventSummary(2, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']),
            ]));
        }
        $batch = $repo->findByLifecycleId($lifecycle);
        $this->assertSame($intentKind, $batch->interruptionKind, 'First-wins kind persisted before registration');

        $oppositeKind = DeferredSubagentInterruptionKindEnum::Timeout === $intentKind
            ? DeferredSubagentInterruptionKindEnum::ParentCancelled
            : DeferredSubagentInterruptionKindEnum::Timeout;
        $interruptionService->interrupt($lifecycle, $oppositeKind);
        $batch = $repo->findByLifecycleId($lifecycle);
        $this->assertSame($intentKind, $batch->interruptionKind, 'Opposite kind before registration does not override first-wins');
        if ('natural_terminal' === $variant) {
            // Observation after the first interrupt marks the child terminal, so
            // the opposite-kind interrupt must not cancel again.
            $this->assertCount(1, $cancelCalls, 'Terminal child must not be recancelled');
        } else {
            $this->assertCount(2, $cancelCalls, 'Opposite kind before registration recancels the still-active child');
            $this->assertSame($c1['childRunId'], $cancelCalls[1]['runId']);
        }
        $this->assertSame($c1['childRunId'], $cancelCalls[0]['runId']);
        $this->assertCount(0, $commandBus->messages, 'No completion before registration');

        $deferred = self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class);
        $deferred->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: $lifecycle,
            runId: $parent,
            turnNo: 2,
            stepId: 'turn-2-tools-1',
            attempt: 1,
            idempotencyKey: 'idem-single-int-'.$tag,
            toolCallId: $tool,
            toolName: 'subagent',
            arguments: [],
            orderIndex: 0,
        ));

        $interruptionService->interrupt($lifecycle, $oppositeKind);

        if ($expectCancel) {
            $this->assertCount(3, $cancelCalls, 'Active child cancelled again after registration');
            $this->assertSame($c1['childRunId'], $cancelCalls[0]['runId']);
            $this->assertSame($c1['childRunId'], $cancelCalls[1]['runId']);
            $this->assertSame($c1['childRunId'], $cancelCalls[2]['runId']);
            if ('timeout' === $kind) {
                $this->assertSame('Subagent timed out.', $cancelCalls[0]['reason']);
                $this->assertSame('Subagent timed out.', $cancelCalls[1]['reason']);
                $this->assertSame('Subagent timed out.', $cancelCalls[2]['reason']);
            } else {
                $this->assertSame('Parent run cancelled subagent tool.', $cancelCalls[0]['reason']);
                $this->assertSame('Parent run cancelled subagent tool.', $cancelCalls[1]['reason']);
                $this->assertSame('Parent run cancelled subagent tool.', $cancelCalls[2]['reason']);
            }
        } else {
            // natural_terminal: first interrupt cancels the still-running child
            // before observation marks it completed; later interrupts are no-ops.
            $this->assertCount(1, $cancelCalls);
            $this->assertSame($c1['childRunId'], $cancelCalls[0]['runId']);
        }

        $this->assertCount(1, $commandBus->messages);
        $complete = $commandBus->messages[0];
        $this->assertInstanceOf(CompleteDeferredToolCall::class, $complete);
        $presentation = $complete->content[0]['text'];

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $art = $registry->get($parent, $c1['artifactId']);
        $batchDone = $repo->findByLifecycleId($lifecycle);

        if ('timeout' === $kind) {
            $this->assertFalse($complete->isError);
            $this->assertNull($complete->error);
            $this->assertNull($complete->details);
            $this->assertStringStartsWith('Subagent s-int timed out after '.$timeoutSecs.' seconds.', $presentation);
            $this->assertSame(AgentArtifactStatusEnum::Failed, $art->status);
            $this->assertSame('Child run timed out.', $art->failureReason);
            $this->assertSame('Timed out after '.$timeoutSecs.'s.', $art->summary ?? '');
            if ('running_projection' === $variant) {
                $this->assertCount(2, $appended);
                $forced = $appended[1];
                $this->assertSame('single', $forced['mode']);
                $this->assertSame('failed', $forced['status']);
                $this->assertArrayHasKey('input_tokens', $forced);
                $this->assertNotNull($batchDone->interruptionProgressEnqueuedAt);
            } elseif ('natural_terminal' === $variant) {
                $this->assertCount(1, $appended);
                $this->assertNotNull($batchDone->interruptionProgressEnqueuedAt);
            } else {
                // no_projection still emits identity-only forced progress from launch model/reasoning.
                $this->assertCount(1, $appended);
                $this->assertSame('failed', $appended[0]['status']);
                $this->assertNotNull($batchDone->interruptionProgressEnqueuedAt);
            }
        } else {
            $this->assertTrue($complete->isError);
            $this->assertTrue($complete->details['cancelled'] ?? false);
            $this->assertTrue($complete->error['cancelled'] ?? false);
            $this->assertStringStartsWith('Subagent s-int cancelled by parent run.', $presentation);
            $this->assertSame(AgentArtifactStatusEnum::Cancelled, $art->status);
            $this->assertSame('Cancelled by parent run.', $art->summary);
            if ('running_projection' === $variant) {
                $this->assertCount(2, $appended);
                $forced = $appended[1];
                $this->assertSame('single', $forced['mode']);
                $this->assertSame('cancelled', $forced['status']);
                $this->assertArrayHasKey('input_tokens', $forced);
                $this->assertNotNull($batchDone->interruptionProgressEnqueuedAt);
            } else {
                if ('natural_terminal' === $variant) {
                    $this->assertCount(1, $appended);
                    $this->assertNotNull($batchDone->interruptionProgressEnqueuedAt);
                } else {
                    // no_projection still emits identity-only forced progress from launch model/reasoning.
                    $this->assertCount(1, $appended);
                    $this->assertSame('cancelled', $appended[0]['status']);
                    $this->assertNotNull($batchDone->interruptionProgressEnqueuedAt);
                }
            }
        }

        if ('natural_terminal' === $variant) {
            if ('timeout' === $kind) {
                $this->assertSame(AgentArtifactStatusEnum::Failed, $art->status, 'Interruption owns artifact even after natural terminal projection');
            } else {
                $this->assertSame(AgentArtifactStatusEnum::Cancelled, $art->status, 'Interruption owns artifact even after natural terminal projection');
            }
        }

        $cursorBeforeLate = $batchDone->children[0]->childEventCursor;
        $revisionBeforeLate = $batchDone->aggregateProgressRevision;
        $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 2, $c1['childRunId'], RunStatus::Running, 2, [
            new AfterTurnCommitEventSummary(2, RunEventTypeEnum::LlmStepCompleted->value, ['assistant_message' => ['content' => [['type' => 'text', 'text' => 'late']]]]),
        ]));
        $batchLate = $repo->findByLifecycleId($lifecycle);
        $this->assertSame($cursorBeforeLate, $batchLate->children[0]->childEventCursor);
        $this->assertSame($revisionBeforeLate, $batchLate->aggregateProgressRevision);

        $commandBus->messages = [];
        $interruptionService->interrupt($lifecycle, $oppositeKind);
        $this->assertCount(0, $commandBus->messages, 'Late observe and repeat interrupt stay idempotent');
    }

    public static function singleNaturalTerminalScenarioProvider(): array
    {
        return [
            'completed' => ['completed'],
            'failed' => ['failed'],
            'cancelled' => ['cancelled'],
        ];
    }

    public static function singleInterruptionScenarioProvider(): array
    {
        return [
            'timeout_running_projection' => ['timeout', 'to-run', 'running_projection', true],
            'parent_cancel_running_projection' => ['parent_cancelled', 'pc-run', 'running_projection', true],
            'timeout_no_projection' => ['timeout', 'to-noproj', 'no_projection', true],
            'parent_cancel_natural_terminal' => ['parent_cancelled', 'pc-nat', 'natural_terminal', false],
        ];
    }

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

    public function testParentCancelWhileChildStillReservedCancelsChildAndCompletesAfterRegistration(): void
    {
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $factory = new DeferredSubagentBatchIdentityFactory();
        $parent = 'parent-batch-reserved-pc';
        $tool = 'tool-batch-reserved-pc';
        $lifecycle = $factory->batchLifecycleId($parent, $tool);
        $c1 = $factory->childIdentity($parent, $tool, 1);

        $repo->reserveBatch(
            lifecycleId: $lifecycle,
            parentRunId: $parent,
            parentTurnNo: 2,
            parentToolCallId: $tool,
            parentOrderIndex: 0,
            executionMode: ChildRunBatchExecutionModeEnum::Single,
            totalChildCount: 1,
            deadlineAt: new \DateTimeImmutable('+600 seconds'),
            childIntents: [
                ['batchIndex' => 1, 'childRunId' => $c1['childRunId'], 'artifactId' => $c1['artifactId'], 'agentName' => 'fork', 'task' => 'Late start', 'launchModel' => 'deepseek/deepseek-v4-flash', 'launchReasoning' => 'medium'],
            ],
        );
        $this->ensureArtifactReserved($parent, $c1['childRunId'], $c1['artifactId'], 'fork', 'Late start', AgentArtifactKindEnum::Fork);

        $batch = $repo->findByLifecycleId($lifecycle);
        $this->assertNotNull($batch);
        $this->assertSame(\Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildLaunchStatusEnum::Reserved, $batch->children[0]->launchStatus);

        $cancelCalls = [];
        $agentRunner = new class($cancelCalls) implements AgentRunnerInterface {
            public function __construct(private array &$calls)
            {
            }

            public function start(StartRunInput $input): string
            {
                throw new \RuntimeException('Reserved child must not start after parent cancel.');
            }

            public function shell(string $runId, string $rawInput): void
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

        $commandBus = new TestMessageBus();
        $interruptionService = new DeferredSubagentBatchInterruptionService(
            $repo,
            $this->buildLifecycleDelivery($commandBus),
            $agentRunner,
            self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchLifecyclePolicyDTO::class),
            $commandBus,
            new TestLogger(),
            new MockClock(new \DateTimeImmutable()),
        );

        $interruptionService->interrupt($lifecycle, DeferredSubagentInterruptionKindEnum::ParentCancelled);

        $this->assertCount(1, $cancelCalls, 'Reserved children must receive cancel before they can start later');
        $this->assertSame($c1['childRunId'], $cancelCalls[0]['runId']);
        $this->assertSame('Parent run cancelled subagent tool.', $cancelCalls[0]['reason']);
        $this->assertCount(0, $commandBus->messages, 'Completion still waits for deferred registration');

        $batch = $repo->findByLifecycleId($lifecycle);
        $this->assertSame(DeferredSubagentInterruptionKindEnum::ParentCancelled, $batch->interruptionKind);
        $this->assertSame(\Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildLaunchStatusEnum::Reserved, $batch->children[0]->launchStatus);

        $deferred = self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class);
        $deferred->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: $lifecycle,
            runId: $parent,
            turnNo: 2,
            stepId: 'turn-2-tools-1',
            attempt: 1,
            idempotencyKey: 'idem-reserved-pc',
            toolCallId: $tool,
            toolName: 'fork',
            arguments: [],
            orderIndex: 0,
        ));

        $interruptionService->interrupt($lifecycle, DeferredSubagentInterruptionKindEnum::ParentCancelled);

        $this->assertCount(1, $commandBus->messages);
        $complete = $commandBus->messages[0];
        $this->assertInstanceOf(CompleteDeferredToolCall::class, $complete);
        $this->assertTrue($complete->isError);
        $this->assertTrue($complete->details['cancelled'] ?? false);
        $this->assertStringStartsWith('Subagent fork cancelled by parent run.', $complete->content[0]['text']);

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $this->assertSame(AgentArtifactStatusEnum::Cancelled, $registry->get($parent, $c1['artifactId'])->status);
    }

    /** @return iterable<string, array{bool}> */
    public static function launchInputCleanupFailures(): iterable
    {
        yield 'cleanup succeeds' => [false];
        yield 'cleanup fails without blocking handoff' => [true];
    }

    #[DataProvider('launchInputCleanupFailures')]
    public function testOriginalForkExecutionRedeliveryAcrossTerminalRegistrationAndCompletion(bool $failCleanup): void
    {
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $factory = new DeferredSubagentBatchIdentityFactory();
        $parent = 'parent-batch-fork-once';
        $tool = 'tool-batch-fork-once';
        $lifecycle = $factory->batchLifecycleId($parent, $tool);
        $c1 = $factory->childIdentity($parent, $tool, 1);
        $inputStore = self::getContainer()->get(\Ineersa\AgentCore\Contract\Tool\ToolLaunchInputStoreInterface::class);
        $reference = $inputStore->publish('fork', $parent, 2, 'turn-2-tools-1', $tool, 'deepseek/deepseek-v4-flash', '', [new AgentMessage('user', [['type' => 'text', 'text' => 'Fork task']])]);
        $original = new \Ineersa\AgentCore\Domain\Message\ExecuteToolCall($parent, 2, 'turn-2-tools-1', 1, 'idem-fork-once', $tool, 'fork', ['task' => 'Fork task'], 0, parentModel: 'deepseek/deepseek-v4-flash', launchContext: $reference);
        $batchStore = self::getContainer()->get(\Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface::class);
        $batchStore->save($parent, 2, 'turn-2-tools-1', new \Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO([$tool => 0], [$tool => $original], [$tool], [], [], false, 1));
        $original = $batchStore->load($parent, 2, 'turn-2-tools-1')->calls[$tool];
        $paths = self::getContainer()->get(\Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface::class);
        $inputPath = \dirname($paths->resolveToolBatchesDirectory($parent)).'/tool-launch-inputs/'.hash('sha256', $tool).'.jsonl';
        $repo->reserveBatch(
            lifecycleId: $lifecycle,
            parentRunId: $parent,
            parentTurnNo: 2,
            parentToolCallId: $tool,
            parentOrderIndex: 0,
            executionMode: ChildRunBatchExecutionModeEnum::Single,
            totalChildCount: 1,
            deadlineAt: new \DateTimeImmutable('+600 seconds'),
            parentModel: 'deepseek/deepseek-v4-flash',
            childIntents: [
                ['batchIndex' => 1, 'childRunId' => $c1['childRunId'], 'artifactId' => $c1['artifactId'], 'agentName' => 'fork', 'task' => 'Fork task', 'launchModel' => 'deepseek/deepseek-v4-flash', 'launchReasoning' => 'medium'],
            ],
        );
        $repo->applyLaunchSuccessState($parent, $tool, $lifecycle, new \DateTimeImmutable(), [1]);
        $this->ensureArtifactReserved($parent, $c1['childRunId'], $c1['artifactId'], 'fork', 'Fork task', AgentArtifactKindEnum::Fork);

        $handler = new ObserveDeferredSubagentBatchChildTurnHandler(
            $repo,
            self::getContainer()->get(\Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository::class),
            new DeferredChildRunEventProjector(AttributeSerializerValidatorTestFactory::denormalizer(), new \Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer())),
            new TestLogger(),
            new TestMessageBus(),
        );
        $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 1, $c1['childRunId'], RunStatus::Completed, 2, [
            new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, [
                'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'fork child done']]],
            ]),
            new AfterTurnCommitEventSummary(2, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']),
        ]));

        $deferred = self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class);
        $commandBus = new TestMessageBus();
        $logger = new TestLogger();
        $cleanupStore = null;
        if ($failCleanup) {
            $cleanupStore = $this->createMock(\Ineersa\AgentCore\Contract\Tool\ToolLaunchInputStoreInterface::class);
            $cleanupStore->expects($this->once())->method('delete')->willThrowException(new \RuntimeException('SECRET PATH AND INPUT CONTENT'));
        }
        $delivery = $this->buildLifecycleDelivery($commandBus, launchInputStore: $cleanupStore, completionLogger: $logger);
        $delivery->deliver($lifecycle);
        $this->assertNull($deferred->status($lifecycle));
        $this->assertFileExists($inputPath, 'Unregistered execution still needs input.');
        $this->assertCount(0, $commandBus->messages);

        self::getContainer()->get(\Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository::class)->replace(new RunState($parent, RunStatus::Running, model: 'deepseek/deepseek-v4-flash'));
        $accessor = self::getContainer()->get(\Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor::class);
        $launch = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchService::class);
        $executor = $this->createMock(\Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface::class);
        $executor->expects($this->once())->method('execute')->willReturnCallback(static function ($call) use ($accessor, $launch, $parent, $tool): \Ineersa\AgentCore\Domain\Tool\ToolResult {
            // Real profiled fork launch/idempotency path after worker input resolution.
            // Provider compaction is outside this registration/redelivery contract.
            $context = new \Ineersa\AgentCore\Application\Tool\ToolContext(
                runId: $parent, turnNo: 2, toolCallId: $tool, toolName: 'fork',
                cancellationToken: new \Ineersa\AgentCore\Contract\Hook\NullCancellationToken(), timeoutSeconds: 120,
                parentModel: 'deepseek/deepseek-v4-flash', launchContext: $call->context['launch_context'],
            );
            $outcome = $accessor->with($context, static fn () => $launch->launchSingleChildProfile($parent, 'Fork task', new \Ineersa\CodingAgent\Agent\Execution\ChildRun\Preparation\DeferredSubagentSingleChildLaunchProfileDTO(
                \Ineersa\CodingAgent\Agent\Fork\ForkInternalAgentDefinition::create('deepseek/deepseek-v4-flash'),
                AgentArtifactKindEnum::Fork, 'fork', $call->context['launch_context']->forkMessages, 'medium',
            )));

            return new \Ineersa\AgentCore\Domain\Tool\ToolResult($tool, 'fork', [], ['raw_result' => $outcome]);
        });
        $notifications = 0;
        $events = new \Symfony\Component\EventDispatcher\EventDispatcher();
        $events->addListener(DeferredToolCompletionRegisteredEvent::class, static function () use (&$notifications): void { ++$notifications; });
        $workerBus = new TestMessageBus();
        $worker = new \Ineersa\AgentCore\Application\Handler\ExecuteToolCallWorker($executor, $workerBus, $deferred, new \Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore(), new \Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader(), eventDispatcher: $events, launchInputStore: $inputStore, toolAuthorization: new \Ineersa\AgentCore\Tests\Support\TestToolExecutionAuthorization());
        $worker($original);
        $this->assertCount(0, $workerBus->messages, json_encode($workerBus->messages, \JSON_THROW_ON_ERROR));
        $this->assertSame('pending', $deferred->status($lifecycle));
        $this->assertSame(1, $notifications);
        $this->assertCount(0, $workerBus->messages);
        $delivery->deliver($lifecycle);
        $this->assertCount(1, $commandBus->messages);
        $complete = $commandBus->messages[0];
        $this->assertInstanceOf(CompleteDeferredToolCall::class, $complete);
        $this->assertSame($lifecycle, $complete->deferredId);
        $this->assertFalse($complete->isError);
        if ($failCleanup) {
            $this->assertFileExists($inputPath);
            $warnings = array_values(array_filter($logger->records, static fn ($record) => 'warning' === $record['level']));
            $this->assertCount(1, $warnings);
            $this->assertSame('deferred_subagent_batch.launch_input_cleanup_failed', $warnings[0]['message']);
            $this->assertSame(\RuntimeException::class, $warnings[0]['context']['exception_class']);
            $this->assertSame($parent, $warnings[0]['context']['run_id']);
            $this->assertStringNotContainsString('SECRET', json_encode($warnings, \JSON_THROW_ON_ERROR));
        } else {
            $this->assertFileDoesNotExist($inputPath);
        }
        $worker($original);
        $this->assertSame(2, $notifications, 'Pending redelivery re-emits registration without reading input.');
        $terminalBus = new TestMessageBus();
        (new CompleteDeferredToolCallHandler($deferred, $terminalBus, new TestLogger()))($complete);
        $this->assertSame('completed', $deferred->status($lifecycle));
        $this->assertCount(1, $terminalBus->messages);
        $worker($original);
        $this->assertSame(2, $notifications, 'Completed original execution redelivery is a no-op.');
        $this->assertCount(0, $workerBus->messages);
        $this->assertCount(1, $repo->findByLifecycleId($lifecycle)->children);
        $commandBus->messages = [];
        $delivery->deliver($lifecycle);
        $this->assertCount(0, $commandBus->messages);
    }

    public function testAgentResumeToolNameDeferredLifecyclePreservesToolNameThroughCompleteDeferredToolCall(): void
    {
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $factory = new DeferredSubagentBatchIdentityFactory();
        $parent = 'parent-batch-agent-resume-once';
        $tool = 'tool-batch-agent-resume-once';
        $lifecycle = $factory->batchLifecycleId($parent, $tool);
        $c1 = $factory->childIdentity($parent, $tool, 1);
        $repo->reserveBatch(
            lifecycleId: $lifecycle,
            parentRunId: $parent,
            parentTurnNo: 2,
            parentToolCallId: $tool,
            parentOrderIndex: 0,
            executionMode: ChildRunBatchExecutionModeEnum::Single,
            totalChildCount: 1,
            deadlineAt: new \DateTimeImmutable('+600 seconds'),
            childIntents: [
                ['batchIndex' => 1, 'childRunId' => $c1['childRunId'], 'artifactId' => $c1['artifactId'], 'agentName' => 'scout', 'task' => 'Resume task', 'launchModel' => 'deepseek/deepseek-v4-flash', 'launchReasoning' => 'medium'],
            ],
        );
        $repo->applyLaunchSuccessState($parent, $tool, $lifecycle, new \DateTimeImmutable(), [1]);
        $this->ensureArtifactReserved($parent, $c1['childRunId'], $c1['artifactId'], 'scout', 'Resume task');

        $handler = new ObserveDeferredSubagentBatchChildTurnHandler(
            $repo,
            self::getContainer()->get(\Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository::class),
            new DeferredChildRunEventProjector(AttributeSerializerValidatorTestFactory::denormalizer(), new \Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer())),
            new TestLogger(),
            new TestMessageBus(),
        );
        $handler(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 1, $c1['childRunId'], RunStatus::Completed, 2, [
            new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, [
                'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'resume child done']]],
            ]),
            new AfterTurnCommitEventSummary(2, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']),
        ]));

        $deferred = self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class);
        $deferred->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: $lifecycle,
            runId: $parent,
            turnNo: 2,
            stepId: 'turn-2-tools-1',
            attempt: 1,
            idempotencyKey: 'idem-agent-resume-once',
            toolCallId: $tool,
            toolName: 'agent_resume',
            arguments: [],
            orderIndex: 0,
        ));

        $commandBus = new TestMessageBus();
        $delivery = $this->buildLifecycleDelivery($commandBus);
        $delivery->deliver($lifecycle);
        $this->assertCount(1, $commandBus->messages);
        $complete = $commandBus->messages[0];
        $this->assertInstanceOf(CompleteDeferredToolCall::class, $complete);
        $this->assertSame($lifecycle, $complete->deferredId);
        $this->assertFalse($complete->isError);

        $resultBus = new TestMessageBus();
        $completionHandler = new CompleteDeferredToolCallHandler(
            $deferred,
            $resultBus,
            new TestLogger(),
        );
        $completionHandler($complete);
        $this->assertCount(1, $resultBus->messages);
        $toolCallResult = $resultBus->messages[0];
        $this->assertInstanceOf(ToolCallResult::class, $toolCallResult);
        $this->assertIsArray($toolCallResult->result);
        $this->assertSame('agent_resume', $toolCallResult->result['tool_name']);

        $commandBus->messages = [];
        $delivery->deliver($lifecycle);
        $this->assertCount(0, $commandBus->messages, 'Redelivered lifecycle observation must not complete parent tool twice');
    }

    public function testFailedChildOutcomeReadsCanonicalPresentation(): void
    {
        $identity = new ChildRunIdentityDTO(
            parentRunId: 'parent-canonical-outcome',
            childRunId: 'child-canonical-outcome',
            artifactId: 'artifact-canonical-outcome',
            displayName: 'scout',
            taskSummary: 'inspect',
            artifactKind: AgentArtifactKindEnum::Subagent,
            batchIndex: 1,
        );
        $events = self::getContainer()->get(\Ineersa\AgentCore\Contract\EventStoreInterface::class);
        $events->append(\Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($identity->childRunId, 0, 'run_started', ['payload' => ['messages' => [['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'partial']]]]]]));
        $events->append(\Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($identity->childRunId, 0, 'agent_end', ['reason' => 'failed']));

        $outcome = $this->createOutcomeFactory()->buildNaturalArtifactOutcome(
            $identity,
            new DeferredChildRunLifecycleProjectionDTO(
                childStatus: RunStatus::Failed,
                childTurnNo: 1,
                lastCommittedSeq: 2,
                model: 'test/model',
                reasoning: 'medium',
                errorMessage: 'failed',
            ),
        );

        $this->assertSame(AgentArtifactStatusEnum::Failed, $outcome->status);
        $this->assertNotNull($outcome->childPresentation);
        $this->assertSame('partial', $outcome->childPresentation->assistantExcerpt);
        $this->assertSame(1, $outcome->childPresentation->messageCount);
        $this->assertSame([], $outcome->childPresentation->historyLines);
    }

    public function testCanonicalChildReadFailureDegradesToSummaryWithoutPresentation(): void
    {
        $identity = new ChildRunIdentityDTO(
            parentRunId: 'parent-canonical-failure',
            childRunId: 'child-canonical-failure',
            artifactId: 'artifact-canonical-failure',
            displayName: 'scout',
            taskSummary: 'inspect',
            artifactKind: AgentArtifactKindEnum::Subagent,
            batchIndex: 1,
        );
        $events = $this->createMock(\Ineersa\AgentCore\Contract\EventStoreInterface::class);
        $events->expects($this->once())->method('latestSequenceFor')->willThrowException(new \RuntimeException('SECRET canonical read failed'));
        $reader = new RunPresentationReader($events, self::getContainer()->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class), self::getContainer()->get(\Ineersa\AgentCore\Application\Replay\ReplayAssistantMessageFactory::class), self::getContainer()->get(\Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec::class));
        $logger = new TestLogger();

        $outcome = $this->createOutcomeFactory($reader, $logger)->buildNaturalArtifactOutcome(
            $identity,
            new DeferredChildRunLifecycleProjectionDTO(
                childStatus: RunStatus::Cancelled,
                childTurnNo: 1,
                lastCommittedSeq: 2,
                model: 'test/model',
                reasoning: 'medium',
            ),
        );

        $this->assertSame(AgentArtifactStatusEnum::Cancelled, $outcome->status);
        $this->assertNull($outcome->childPresentation);
        $this->assertSame('deferred_subagent.child_state_load_failed', $logger->records[0]['message']);
        $this->assertSame($identity->childRunId, $logger->records[0]['context']['child_run_id']);
        $this->assertStringNotContainsString('SECRET', json_encode($logger->records, \JSON_THROW_ON_ERROR));
    }

    #[DataProvider('delayedProgressCases')]
    public function testDelayedOwnerProgressBlocksCompletionUntilConsumption(string $mode, ?string $kind, bool $superseded = false): void
    {
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $factory = new DeferredSubagentBatchIdentityFactory();
        $parent = 'parent-delayed-'.$mode.'-'.($kind ?? 'natural');
        $tool = 'call';
        $lifecycle = $factory->batchLifecycleId($parent, $tool);
        $child = $factory->childIdentity($parent, $tool, 1);
        $repo->reserveBatch($lifecycle, $parent, 2, $tool, 0, ChildRunBatchExecutionModeEnum::from($mode), 1, new \DateTimeImmutable('+1 hour'), [
            ['batchIndex' => 1, 'childRunId' => $child['childRunId'], 'artifactId' => $child['artifactId'], 'agentName' => 'scout', 'task' => 'task', 'launchModel' => 'model', 'launchReasoning' => 'medium'],
        ]);
        $repo->applyLaunchSuccessState($parent, $tool, $lifecycle, new \DateTimeImmutable(), [1]);
        $this->ensureArtifactReserved($parent, $child['childRunId'], $child['artifactId'], 'scout', 'task');
        $observer = new ObserveDeferredSubagentBatchChildTurnHandler($repo,
            self::getContainer()->get(\Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository::class),
            new DeferredChildRunEventProjector(AttributeSerializerValidatorTestFactory::denormalizer(), new \Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer())), new TestLogger(), new TestMessageBus());
        $observer(new ObserveDeferredSubagentBatchChildTurnMessage($lifecycle, 1, $child['childRunId'], null === $kind ? RunStatus::Completed : RunStatus::Running, 1, [
            new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, ['assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'done']]]]),
        ]));
        if (null !== $kind) {
            $row = $repo->findEntityByLifecycleId($lifecycle);
            $row->interruptionKind = DeferredSubagentInterruptionKindEnum::from($kind);
            self::getContainer()->get('doctrine.orm.entity_manager')->flush();
        }
        self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class)->registerPending(new DeferredToolCompletionCorrelation(
            $lifecycle, $parent, 2, 'step', 1, 'idempotency', $tool, 'subagent', [], 0,
        ));
        $inputs = self::getContainer()->get(\Ineersa\AgentCore\Contract\Tool\ToolLaunchInputStoreInterface::class);
        $inputs->publish('subagent', $parent, 2, 'step', $tool, 'model', '', []);
        $paths = self::getContainer()->get(\Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface::class);
        $inputPath = \dirname($paths->resolveToolBatchesDirectory($parent)).'/tool-launch-inputs/'.hash('sha256', $tool).'.jsonl';
        $queued = new TestMessageBus();
        $appender = new SubagentProgressEventAppender($queued, SubagentProgressSerializerTestSupport::normalizer(), SubagentProgressSerializerTestSupport::validator(),
            self::getContainer()->get(RuntimeEventSinkInterface::class), self::getContainer()->get(RuntimeEventMapper::class), false);
        $completionBus = new TestMessageBus();
        $delivery = $this->buildLifecycleDelivery($completionBus, $appender);
        $delivery->deliver($lifecycle);
        $this->assertCount(1, $queued->messages);
        $this->assertSame([], $completionBus->messages);
        $before = $repo->findByLifecycleId($lifecycle);
        $this->assertSame(0, $before->deliveredProgressRevision);
        $this->assertNull($before->interruptionProgressEnqueuedAt);
        $this->assertNull($before->terminalCompletionEnqueuedAt);
        $delivery->deliver($lifecycle);
        $this->assertSame([], $completionBus->messages);
        $this->assertFileExists($inputPath);
        // A retry may queue the same command; only owner consumption commits it.
        $active = self::getContainer()->get(\Ineersa\AgentCore\Contract\ActiveRunContextInterface::class);
        $active->loadRecovered(\Ineersa\AgentCore\Tests\Support\Builder\RunStateBuilder::running($parent)->withTurnNo(2)->withPendingToolCalls([$tool => false])->build());
        if ($superseded) {
            if (null !== $kind) {
                // Cancellation has finished; a follow-up turn owns the context
                // before the old forced command reaches run_control.
                $active->loadRecovered($active->requireLoaded($parent)->with(['status' => RunStatus::Cancelled, 'pendingToolCalls' => []]));
                $active->loadRecovered($active->requireLoaded($parent)->with(['status' => RunStatus::Running, 'turnNo' => 3, 'pendingToolCalls' => ['new-call' => false]]));
            } else {
                $active->loadRecovered($active->requireLoaded($parent)->with(['pendingToolCalls' => [$tool => true, 'sibling' => false]]));
            }
        }
        $bus = self::getContainer()->get('agent.command.bus');
        $bus->dispatch($queued->messages[0], [new \Symfony\Component\Messenger\Stamp\ReceivedStamp('run_control')]);
        $bus->dispatch($queued->messages[1], [new \Symfony\Component\Messenger\Stamp\ReceivedStamp('run_control')]);
        $this->assertSame($superseded ? 0 : 1, $active->requireLoaded($parent)->lastSeq);
        if ($superseded) {
            $this->assertNull(self::getContainer()->get(\Ineersa\AgentCore\Contract\EventStoreInterface::class)->latestSequenceFor($parent));
        }
        $after = $repo->findByLifecycleId($lifecycle);
        if (null === $kind) {
            $this->assertSame($after->aggregateProgressRevision, $after->deliveredProgressRevision);
        } else {
            $this->assertNotNull($after->interruptionProgressEnqueuedAt);
        }
        $delivery->deliver($lifecycle);
        $this->assertCount(1, $completionBus->messages);
        $this->assertInstanceOf(CompleteDeferredToolCall::class, $completionBus->messages[0]);
        $this->assertNotNull($repo->findByLifecycleId($lifecycle)->terminalCompletionEnqueuedAt);
        $this->assertFileDoesNotExist($inputPath);
        $delivery->deliver($lifecycle);
        $this->assertCount(1, $completionBus->messages);
    }

    public static function delayedProgressCases(): iterable
    {
        yield 'single natural' => ['single', null];
        yield 'parallel natural' => ['parallel', null];
        yield 'single cancellation' => ['single', 'parent_cancelled'];
        yield 'parallel cancellation' => ['parallel', 'parent_cancelled'];
        yield 'single timeout' => ['single', 'timeout'];
        yield 'resolved normal call retires its obligation' => ['single', null, true];
        yield 'cancelled single followed by new turn' => ['single', 'parent_cancelled', true];
        yield 'cancelled parallel followed by new turn' => ['parallel', 'parent_cancelled', true];
    }

    /**
     * @param array<int, array<string, mixed>> $appended
     */
    private function createSpyProgressAppender(array &$appended): SubagentProgressEventAppender
    {
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $store = self::getContainer()->get(\Ineersa\AgentCore\Contract\EventStoreInterface::class);
        $lock = self::getContainer()->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class);

        return new class($repo, $store, $lock, $appended) extends SubagentProgressEventAppender {
            public function __construct(
                private DeferredSubagentBatchRepository $repo,
                private \Ineersa\AgentCore\Contract\EventStoreInterface $store,
                private \Ineersa\AgentCore\Application\Handler\RunLockManager $lock,
                private array &$appended,
            ) {
            }

            public function append(
                string $parentRunId, int $parentTurnNo, string $parentToolCallId,
                int $parentOrderIndex, string $toolName,
                \Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSnapshotInterface $progress,
                string $lifecycleId, int $revision, ?string $interruptionKind = null,
            ): bool {
                $normalized = SubagentProgressSerializerTestSupport::normalizer()->normalize($progress);
                $this->appended[] = $normalized;
                $bus = new TestMessageBus();
                $handler = new \Ineersa\CodingAgent\Application\Pipeline\CommitSubagentProgressHandler($this->repo);
                $active = new \Ineersa\AgentCore\Tests\Support\TestActiveRunContext();
                $active->loadRecovered(\Ineersa\AgentCore\Tests\Support\Builder\RunStateBuilder::running($parentRunId)
                    ->withTurnNo($parentTurnNo)->withLastSeq($this->store->latestSequenceFor($parentRunId) ?? 0)->withPendingToolCalls([$parentToolCallId => false])->build());
                $coordination = new \Ineersa\CodingAgent\Application\Pipeline\SubagentProgressCoordinationHandler($this->repo, $bus);
                $coordinationBus = new \Symfony\Component\Messenger\MessageBus([
                    new \Symfony\Component\Messenger\Middleware\HandleMessageMiddleware(new \Symfony\Component\Messenger\Handler\HandlersLocator([
                        \Ineersa\CodingAgent\Application\Message\ConsumeSubagentProgressDTO::class => [$coordination(...)],
                        DeliverDeferredSubagentBatchLifecycleMessage::class => [$bus->dispatch(...)],
                    ])),
                ]);
                $dispatcher = new \Ineersa\AgentCore\Application\Handler\StepDispatcher($coordinationBus, $bus);
                $commit = new \Ineersa\AgentCore\Application\Pipeline\RunCommit($active, $this->store, $dispatcher, new TestLogger(), new \Ineersa\AgentCore\Application\Handler\ToolBatchCollector(), new \Ineersa\AgentCore\Tests\Support\TestToolExecutionAuthorization(), new \Ineersa\AgentCore\Tests\Support\TestExecutionOperationStore(), new \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance(new \Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore()), actionValidator: new \Ineersa\AgentCore\Application\Handler\CoordinationActionValidator([$coordination]));
                $processor = new \Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor($active, $this->lock, $commit, [$handler]);
                $processor->process('command.subagent_progress', new \Ineersa\AgentCore\Domain\Message\CommitSubagentProgress(
                    $parentRunId, $parentTurnNo, $lifecycleId, $parentToolCallId, $parentOrderIndex, $revision, $normalized, $interruptionKind,
                ));

                return true;
            }
        };
    }

    private function createSnapshotFactory(): DeferredSubagentBatchProgressSnapshotFactory
    {
        return new DeferredSubagentBatchProgressSnapshotFactory(
            $this->createOutcomeFactory(),
            self::getContainer()->get(SubagentChildProgressSummaryBuilder::class),
            self::getContainer()->get(SubagentProgressSnapshotBuilder::class),
        );
    }

    private function createOutcomeFactory(?RunPresentationReader $reader = null, ?TestLogger $logger = null): DeferredSubagentBatchChildOutcomeFactory
    {
        return new DeferredSubagentBatchChildOutcomeFactory(
            $reader ?? self::getContainer()->get(RunPresentationReader::class),
            $logger ?? new TestLogger(),
        );
    }

    private function buildLifecycleDelivery(TestMessageBus $commandBus, ?SubagentProgressEventAppender $spyAppender = null, ?\Ineersa\AgentCore\Contract\Tool\ToolLaunchInputStoreInterface $launchInputStore = null, ?TestLogger $completionLogger = null): DeferredSubagentBatchLifecycleDeliveryService
    {
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $ignoredProgress = [];
        $progress = new DeferredSubagentBatchProgressDeliveryService(
            $repo,
            $this->createSnapshotFactory(),
            $spyAppender ?? $this->createSpyProgressAppender($ignoredProgress),
            new TestLogger(),
        );
        $completionDispatcher = new DeferredSubagentBatchCompletionDispatcher(
            self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class),
            $repo,
            $commandBus,
            $completionLogger ?? new TestLogger(),
            $launchInputStore ?? self::getContainer()->get(\Ineersa\AgentCore\Contract\Tool\ToolLaunchInputStoreInterface::class),
        );
        $outcomeFactory = $this->createOutcomeFactory();
        $handoffRenderer = self::getContainer()->get(SubagentChildRunHandoffRenderer::class);
        $naturalCompletion = new DeferredSubagentBatchTerminalCompletionService(
            self::getContainer()->get(SubagentChildRunBatchLifecycleListener::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentParallelAggregateResultFormatter::class),
            $handoffRenderer,
            $completionDispatcher,
            $outcomeFactory,
        );
        $interruptionCompletion = new DeferredSubagentBatchInterruptionCompletionService(
            $repo,
            self::getContainer()->get(SubagentChildRunBatchLifecycleListener::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentParallelAggregateResultFormatter::class),
            $handoffRenderer,
            $progress,
            $completionDispatcher,
            $outcomeFactory,
        );

        return new DeferredSubagentBatchLifecycleDeliveryService($repo, $progress, $naturalCompletion, $interruptionCompletion);
    }

    private function ensureArtifactReserved(
        string $parentRunId,
        string $childRunId,
        string $artifactId,
        string $agentName,
        string $task,
        AgentArtifactKindEnum $artifactKind = AgentArtifactKindEnum::Subagent,
    ): void {
        $lifecycle = self::getContainer()->get(ChildRunArtifactLifecycleService::class);
        $lifecycle->ensureReservedPending(new ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: $childRunId,
            artifactId: $artifactId,
            displayName: $agentName,
            taskSummary: $task,
            artifactKind: $artifactKind,
            batchIndex: 1));
    }
}
