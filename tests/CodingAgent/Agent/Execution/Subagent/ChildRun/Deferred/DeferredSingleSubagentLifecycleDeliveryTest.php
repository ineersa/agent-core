<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\ChildRun\Deferred;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Event\DeferredToolCompletionRegisteredEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunEventProjector;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSingleSubagentInterruptionService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSingleSubagentLifecycleDeliveryService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSingleSubagentParentCancelHookSubscriber;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSingleSubagentTerminalCompletionService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredToolCompletionRegisteredSubagentListener;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\InterruptDeferredSingleSubagentMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\ObserveDeferredSingleSubagentChildTurnHandler;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\ObserveDeferredSingleSubagentChildTurnMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Progress\SubagentProgressEventAppender;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Result\SubagentChildRunHandoffRenderer;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\SubagentChildRunBatchLifecycleListener;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder;
use Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder;
use Ineersa\CodingAgent\Entity\DeferredSingleSubagentLaunchRepository;
use Ineersa\CodingAgent\Session\CommittedRunEventAppender;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('db')]
final class DeferredSingleSubagentLifecycleDeliveryTest extends IsolatedKernelTestCase
{
    public function testRegistrationEventEnqueuesDeliveryForMatchingLifecycle(): void
    {
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $deadline = new \DateTimeImmutable('+600 seconds');
        $projection = $repo->reserve(
            parentRunId: 'parent-reg',
            parentTurnNo: 2,
            parentToolCallId: 'tool-reg',
            parentOrderIndex: 3,
            childRunId: 'child-reg-uuid',
            artifactId: 'agent_dddddddddddddddd',
            agentName: 'worker',
            task: 'Do work',
            definitionModel: null,
            deadlineAt: $deadline,
        );
        $repo->markLaunched('parent-reg', 'tool-reg', new \DateTimeImmutable());

        $bus = new TestMessageBus();
        $listener = new DeferredToolCompletionRegisteredSubagentListener($repo, $bus);
        $listener(new DeferredToolCompletionRegisteredEvent(new DeferredToolCompletionCorrelation(
            deferredId: $projection->lifecycleId,
            runId: 'parent-reg',
            turnNo: 2,
            stepId: 'turn-2-tools-1',
            attempt: 1,
            idempotencyKey: 'idemp',
            toolCallId: 'tool-reg',
            toolName: 'subagent',
            arguments: [],
            orderIndex: 3,
        )));

        $this->assertCount(2, $bus->messages);
        $this->assertSame($projection->lifecycleId, $bus->messages[0]->lifecycleId);
        $this->assertInstanceOf(InterruptDeferredSingleSubagentMessage::class, $bus->messages[1]);
    }

    public function testDeliveryEmitsParentProgressAndCompletesDeferredToolWithNormalPresentation(): void
    {
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $deadline = new \DateTimeImmutable('+600 seconds');
        $launch = $repo->reserve(
            parentRunId: 'parent-deliver',
            parentTurnNo: 4,
            parentToolCallId: 'tool-deliver',
            parentOrderIndex: 1,
            childRunId: 'child-deliver-uuid',
            artifactId: 'agent_ffffffffffffffff',
            agentName: 'worker',
            task: 'Finish',
            definitionModel: null,
            deadlineAt: $deadline,
        );
        $repo->markLaunched('parent-deliver', 'tool-deliver', new \DateTimeImmutable('-2 seconds'));
        $this->ensureArtifactReserved(
            parentRunId: 'parent-deliver',
            childRunId: 'child-deliver-uuid',
            artifactId: 'agent_ffffffffffffffff',
            agentName: 'worker',
            task: 'Finish',
        );

        $observeBus = new TestMessageBus();
        $observeHandler = new ObserveDeferredSingleSubagentChildTurnHandler(
            $repo,
            new DeferredChildRunEventProjector(),
            new TestLogger(),
            $observeBus,
        );
        $observeHandler(new ObserveDeferredSingleSubagentChildTurnMessage(
            lifecycleId: $launch->lifecycleId,
            childRunId: 'child-deliver-uuid',
            committedStatus: RunStatus::Completed,
            turnNo: 2,
            committedEvents: [
                new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, [
                    'usage' => ['input_tokens' => 2, 'output_tokens' => 1, 'total_tokens' => 3],
                    'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'First block'], ['type' => 'text', 'text' => 'ignored']]],
                ]),
                new AfterTurnCommitEventSummary(2, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']),
            ],
        ));

        $this->assertGreaterThanOrEqual(1, \count($observeBus->messages));

        $deferredRepo = self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class);
        $deferredRepo->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: $launch->lifecycleId,
            runId: 'parent-deliver',
            turnNo: 4,
            stepId: 'turn-4-tools-1',
            attempt: 1,
            idempotencyKey: 'idemp-deliver',
            toolCallId: 'tool-deliver',
            toolName: 'subagent',
            arguments: [],
            orderIndex: 1,
        ));

        $commandBus = new TestMessageBus();
        $delivery = $this->buildDeliveryService($commandBus);
        $delivery->deliver($launch->lifecycleId);

        $this->assertCount(1, $commandBus->messages);
        $this->assertInstanceOf(CompleteDeferredToolCall::class, $commandBus->messages[0]);
        /** @var CompleteDeferredToolCall $complete */
        $complete = $commandBus->messages[0];
        $this->assertSame($launch->lifecycleId, $complete->deferredId);
        $this->assertFalse($complete->isError);
        $this->assertNull($complete->error);
        $this->assertStringContainsString('First block', $complete->content[0]['text']);
        $this->assertStringNotContainsString('ignored', $complete->content[0]['text']);

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $entry = $registry->get('parent-deliver', 'agent_ffffffffffffffff');
        $this->assertNotNull($entry);
        $this->assertSame(AgentArtifactStatusEnum::Completed, $entry->status);
        $this->assertSame('First block', $entry->summary);

        $row = $repo->findByLifecycleId($launch->lifecycleId);
        $this->assertNotNull($row);
        $this->assertSame(2, $row->parentProgressCursor);
        $this->assertNotNull($row->terminalCompletionEnqueuedAt);

        $delivery->deliver($launch->lifecycleId);
        $this->assertCount(1, $commandBus->messages);
    }

    public function testFailedAndCancelledDeliveriesUseNormalPresentationNotToolErrors(): void
    {
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $deadline = new \DateTimeImmutable('+600 seconds');

        $failedLaunch = $repo->reserve('parent-fail', 1, 'tool-fail', 0, 'child-fail', 'agent_1111111111111111', 'worker', 'Fail', null, $deadline);
        $repo->markLaunched('parent-fail', 'tool-fail', new \DateTimeImmutable());
        $this->ensureArtifactReserved('parent-fail', 'child-fail', 'agent_1111111111111111', 'worker', 'Fail');
        $this->projectTerminal($repo, $failedLaunch->lifecycleId, 'child-fail', RunStatus::Failed, [
            new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepFailed->value, ['error' => ['message' => 'boom']]),
        ]);
        $deferredRepo = self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class);
        $deferredRepo->registerPending($this->correlation($failedLaunch->lifecycleId, 'parent-fail', 'tool-fail'));
        $bus = new TestMessageBus();
        $this->buildDeliveryService($bus)->deliver($failedLaunch->lifecycleId);
        $this->assertInstanceOf(CompleteDeferredToolCall::class, $bus->messages[0]);
        $this->assertFalse($bus->messages[0]->isError);
        $this->assertStringContainsString('boom', $bus->messages[0]->content[0]['text']);

        $cancelLaunch = $repo->reserve('parent-cancel', 1, 'tool-cancel', 0, 'child-cancel', 'agent_2222222222222222', 'worker', 'Cancel', null, $deadline);
        $repo->markLaunched('parent-cancel', 'tool-cancel', new \DateTimeImmutable());
        $this->ensureArtifactReserved('parent-cancel', 'child-cancel', 'agent_2222222222222222', 'worker', 'Cancel');
        $this->projectTerminal($repo, $cancelLaunch->lifecycleId, 'child-cancel', RunStatus::Cancelled, [
            new AfterTurnCommitEventSummary(1, RunEventTypeEnum::AgentEnd->value, ['reason' => 'cancelled']),
        ]);
        $deferredRepo->registerPending($this->correlation($cancelLaunch->lifecycleId, 'parent-cancel', 'tool-cancel'));
        $bus2 = new TestMessageBus();
        $this->buildDeliveryService($bus2)->deliver($cancelLaunch->lifecycleId);
        $this->assertFalse($bus2->messages[0]->isError);
        $this->assertStringContainsString('was cancelled', $bus2->messages[0]->content[0]['text']);

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $this->assertSame(AgentArtifactStatusEnum::Failed, $registry->get('parent-fail', 'agent_1111111111111111')?->status);
        $this->assertSame(AgentArtifactStatusEnum::Cancelled, $registry->get('parent-cancel', 'agent_2222222222222222')?->status);
    }

    public function testRegistrationListenerSchedulesDelayedTimeoutInterrupt(): void
    {
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $deadline = new \DateTimeImmutable('+30 seconds');
        $projection = $repo->reserve('parent-timeout-sched', 1, 'tool-timeout-sched', 0, 'child-timeout-sched', 'agent_3333333333333333', 'worker', 'Wait', null, $deadline);
        $repo->markLaunched('parent-timeout-sched', 'tool-timeout-sched', new \DateTimeImmutable());

        $bus = new DelayStampCapturingBus();
        $listener = new DeferredToolCompletionRegisteredSubagentListener($repo, $bus);
        $listener(new DeferredToolCompletionRegisteredEvent(new DeferredToolCompletionCorrelation(
            deferredId: $projection->lifecycleId,
            runId: 'parent-timeout-sched',
            turnNo: 1,
            stepId: 'turn-1-tools-1',
            attempt: 1,
            idempotencyKey: 'idemp',
            toolCallId: 'tool-timeout-sched',
            toolName: 'subagent',
            arguments: [],
            orderIndex: 0,
        )));

        $this->assertCount(2, $bus->messages);
        $this->assertInstanceOf(InterruptDeferredSingleSubagentMessage::class, $bus->messages[1]);
        $this->assertSame(DeferredSubagentInterruptionKindEnum::Timeout, $bus->messages[1]->kind);
        $delay = $this->extractDelayMs($bus->stampSets[1]);
        $this->assertGreaterThanOrEqual(25_000, $delay);
    }

    public function testTimeoutInterruptionEnforcesForegroundSemanticsAndIdempotentCompletion(): void
    {
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $started = new \DateTimeImmutable('-120 seconds');
        $deadline = $started->modify('+60 seconds');
        $launch = $repo->reserve('parent-timeout', 2, 'tool-timeout', 1, 'child-timeout', 'agent_4444444444444444', 'worker', 'Slow task', null, $deadline);
        $repo->markLaunched('parent-timeout', 'tool-timeout', $started);
        $this->ensureArtifactReserved('parent-timeout', 'child-timeout', 'agent_4444444444444444', 'worker', 'Slow task');

        $runner = new RecordingAgentRunner();
        $commandBus = new TestMessageBus();
        $interruption = new DeferredSingleSubagentInterruptionService(
            $repo,
            $this->buildDeliveryService($commandBus),
            $runner,
            self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class),
            new \Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildRunBatchLifecyclePolicyFactory(),
            $commandBus,
            new TestLogger(),
        );

        $deferredRepo = self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class);
        $deferredRepo->registerPending($this->correlation($launch->lifecycleId, 'parent-timeout', 'tool-timeout'));
        $interruption->interrupt($launch->lifecycleId, DeferredSubagentInterruptionKindEnum::Timeout);
        $this->assertSame(['child-timeout'], $runner->cancelledChildRunIds);
        $this->assertSame('Subagent timed out.', $runner->lastReason);

        $this->assertCount(1, $commandBus->messages);
        $complete = $commandBus->messages[0];
        $this->assertInstanceOf(CompleteDeferredToolCall::class, $complete);
        $this->assertFalse($complete->isError);
        $this->assertStringContainsString('timed out after 60 seconds', $complete->content[0]['text']);

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $entry = $registry->get('parent-timeout', 'agent_4444444444444444');
        $this->assertSame(AgentArtifactStatusEnum::Failed, $entry?->status);
        $this->assertSame('Child run timed out.', $entry?->failureReason);
        $this->assertSame('Timed out after 60s.', $entry?->summary);

        $interruption->interrupt($launch->lifecycleId, DeferredSubagentInterruptionKindEnum::Timeout);
        $this->assertCount(1, $commandBus->messages);
    }

    public function testEarlyTimeoutInterruptRedispatchesWithoutCancel(): void
    {
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $deadline = new \DateTimeImmutable('+120 seconds');
        $launch = $repo->reserve('parent-early-timeout', 1, 'tool-early', 0, 'child-early', 'agent_5555555555555555', 'worker', 'Later', null, $deadline);
        $repo->markLaunched('parent-early-timeout', 'tool-early', new \DateTimeImmutable());

        $runner = new RecordingAgentRunner();
        $bus = new DelayStampCapturingBus();
        $interruption = new DeferredSingleSubagentInterruptionService(
            $repo,
            $this->buildDeliveryService(new TestMessageBus()),
            $runner,
            self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class),
            new \Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildRunBatchLifecyclePolicyFactory(),
            $bus,
            new TestLogger(),
        );

        $interruption->interrupt($launch->lifecycleId, DeferredSubagentInterruptionKindEnum::Timeout);
        $this->assertSame([], $runner->cancelledChildRunIds);
        $this->assertCount(1, $bus->messages);
        $this->assertInstanceOf(InterruptDeferredSingleSubagentMessage::class, $bus->messages[0]);
        $this->assertGreaterThan(60_000, $this->extractDelayMs($bus->stampSets[0]));
    }

    public function testParentCancelHookPropagatesErrorCompletionEnvelope(): void
    {
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $deadline = new \DateTimeImmutable('+600 seconds');
        $launch = $repo->reserve('parent-pcancel', 3, 'tool-pcancel', 2, 'child-pcancel', 'agent_6666666666666666', 'worker', 'Cancel me', null, $deadline);
        $repo->markLaunched('parent-pcancel', 'tool-pcancel', new \DateTimeImmutable());
        $this->ensureArtifactReserved('parent-pcancel', 'child-pcancel', 'agent_6666666666666666', 'worker', 'Cancel me');

        $bus = new TestMessageBus();
        $hook = new DeferredSingleSubagentParentCancelHookSubscriber($repo, $bus);
        $hook->handleAfterTurnCommit(new AfterTurnCommitHookContext(
            runId: 'parent-pcancel',
            turnNo: 3,
            status: RunStatus::Cancelling->value,
            events: [],
            effectsCount: 0,
        ));
        $this->assertCount(1, $bus->messages);
        $this->assertInstanceOf(InterruptDeferredSingleSubagentMessage::class, $bus->messages[0]);
        $this->assertSame(DeferredSubagentInterruptionKindEnum::ParentCancelled, $bus->messages[0]->kind);

        $runner = new RecordingAgentRunner();
        $commandBus = new TestMessageBus();
        $interruption = new DeferredSingleSubagentInterruptionService(
            $repo,
            $this->buildDeliveryService($commandBus),
            $runner,
            self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class),
            new \Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildRunBatchLifecyclePolicyFactory(),
            $commandBus,
            new TestLogger(),
        );
        $deferredRepo = self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class);
        $deferredRepo->registerPending($this->correlation($launch->lifecycleId, 'parent-pcancel', 'tool-pcancel'));
        $interruption->interrupt($launch->lifecycleId, DeferredSubagentInterruptionKindEnum::ParentCancelled);

        $this->assertTrue($commandBus->messages[0]->isError);
        $this->assertStringContainsString('cancelled by parent run', $commandBus->messages[0]->content[0]['text']);
        $this->assertSame(\Ineersa\AgentCore\Contract\Tool\ToolCallException::class, $commandBus->messages[0]->error['type']);
        $this->assertTrue($commandBus->messages[0]->details['cancelled'] ?? false);
        $this->assertTrue($commandBus->messages[0]->error['cancelled'] ?? false);

        $hook->handleAfterTurnCommit(new AfterTurnCommitHookContext(
            runId: 'parent-pcancel',
            turnNo: 4,
            status: RunStatus::Cancelled->value,
            events: [],
            effectsCount: 0,
        ));
        $this->assertCount(1, $bus->messages);
    }

    public function testTerminalChildBeforeStaleTimeoutUsesNaturalCompletion(): void
    {
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $deadline = new \DateTimeImmutable('-5 seconds');
        $launch = $repo->reserve('parent-race', 1, 'tool-race', 0, 'child-race', 'agent_7777777777777777', 'worker', 'Done', null, $deadline);
        $repo->markLaunched('parent-race', 'tool-race', new \DateTimeImmutable('-30 seconds'));
        $this->ensureArtifactReserved('parent-race', 'child-race', 'agent_7777777777777777', 'worker', 'Done');
        $this->projectTerminal($repo, $launch->lifecycleId, 'child-race', RunStatus::Completed, [
            new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, [
                'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'done']]],
            ]),
            new AfterTurnCommitEventSummary(2, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']),
        ]);

        $runner = new RecordingAgentRunner();
        $commandBus = new TestMessageBus();
        $interruption = new DeferredSingleSubagentInterruptionService(
            $repo,
            $this->buildDeliveryService($commandBus),
            $runner,
            self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class),
            new \Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildRunBatchLifecyclePolicyFactory(),
            $commandBus,
            new TestLogger(),
        );
        $deferredRepo = self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class);
        $deferredRepo->registerPending($this->correlation($launch->lifecycleId, 'parent-race', 'tool-race'));
        $interruption->interrupt($launch->lifecycleId, DeferredSubagentInterruptionKindEnum::Timeout);

        $this->assertSame([], $runner->cancelledChildRunIds);
        $this->assertFalse($commandBus->messages[0]->isError);
        $this->assertStringContainsString('completed', $commandBus->messages[0]->content[0]['text']);
    }

    public function testLateChildObservationAfterSyntheticInterruptionDoesNotEmitProgress(): void
    {
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $started = new \DateTimeImmutable('-90 seconds');
        $deadline = $started->modify('+60 seconds');
        $launch = $repo->reserve('parent-late', 1, 'tool-late', 0, 'child-late', 'agent_8888888888888888', 'worker', 'Interrupted', null, $deadline);
        $repo->markLaunched('parent-late', 'tool-late', $started);
        $this->ensureArtifactReserved('parent-late', 'child-late', 'agent_8888888888888888', 'worker', 'Interrupted');

        $runner = new RecordingAgentRunner();
        $commandBus = new TestMessageBus();
        $delivery = $this->buildDeliveryService($commandBus);
        $interruption = new DeferredSingleSubagentInterruptionService(
            $repo,
            $delivery,
            $runner,
            self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class),
            new \Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildRunBatchLifecyclePolicyFactory(),
            $commandBus,
            new TestLogger(),
        );
        $deferredRepo = self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class);
        $deferredRepo->registerPending($this->correlation($launch->lifecycleId, 'parent-late', 'tool-late'));
        $interruption->interrupt($launch->lifecycleId, DeferredSubagentInterruptionKindEnum::Timeout);
        $this->assertCount(1, $commandBus->messages);

        $observeBus = new TestMessageBus();
        $observeHandler = new ObserveDeferredSingleSubagentChildTurnHandler(
            $repo,
            new DeferredChildRunEventProjector(),
            new TestLogger(),
            $observeBus,
        );
        $observeHandler(new ObserveDeferredSingleSubagentChildTurnMessage(
            lifecycleId: $launch->lifecycleId,
            childRunId: 'child-late',
            committedStatus: RunStatus::Completed,
            turnNo: 2,
            committedEvents: [
                new AfterTurnCommitEventSummary(1, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']),
            ],
        ));
        $this->assertCount(0, $observeBus->messages);
        $delivery->deliver($launch->lifecycleId);
        $this->assertCount(1, $commandBus->messages);
    }

    public function testInterruptionEmitsTerminalProgressWhenChildCursorAlreadyDelivered(): void
    {
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $deadline = new \DateTimeImmutable('+600 seconds');
        $launch = $repo->reserve('parent-int-progress', 2, 'tool-int-progress', 1, 'child-int-progress', 'agent_aaaaaaaaaaaaaaaa', 'worker', 'Work', null, $deadline);
        $repo->markLaunched('parent-int-progress', 'tool-int-progress', new \DateTimeImmutable('-5 seconds'));
        $this->ensureArtifactReserved('parent-int-progress', 'child-int-progress', 'agent_aaaaaaaaaaaaaaaa', 'worker', 'Work');

        $this->projectTerminal($repo, $launch->lifecycleId, 'child-int-progress', RunStatus::Running, [
            new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, [
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
                'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'working']]],
            ]),
        ]);

        $capturingAppender = new CapturingSubagentProgressEventAppender(self::getContainer()->get(CommittedRunEventAppender::class));
        $commandBus = new TestMessageBus();
        $delivery = $this->buildDeliveryService($commandBus, $capturingAppender);
        $deferredRepo = self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class);
        $deferredRepo->registerPending($this->correlation($launch->lifecycleId, 'parent-int-progress', 'tool-int-progress'));
        $delivery->deliver($launch->lifecycleId);
        $this->assertCount(1, $capturingAppender->progressStatuses);
        $this->assertSame('running', $capturingAppender->progressStatuses[0]);

        $runner = new RecordingAgentRunner();
        $interruption = new DeferredSingleSubagentInterruptionService(
            $repo,
            $delivery,
            $runner,
            self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class),
            new \Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildRunBatchLifecyclePolicyFactory(),
            $commandBus,
            new TestLogger(),
        );
        $interruption->interrupt($launch->lifecycleId, DeferredSubagentInterruptionKindEnum::ParentCancelled);

        $this->assertCount(2, $capturingAppender->progressStatuses);
        $this->assertSame('cancelled', $capturingAppender->progressStatuses[1]);
        $this->assertTrue($commandBus->messages[0]->isError);

        $interruption->interrupt($launch->lifecycleId, DeferredSubagentInterruptionKindEnum::ParentCancelled);
        $this->assertCount(2, $capturingAppender->progressStatuses);
        $this->assertCount(1, $commandBus->messages);
    }

    public function testTimeoutDurationUsesCreatedAtWhenStartedAtMissing(): void
    {
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $deadline = (new \DateTimeImmutable())->modify('+120 seconds');
        $launch = $repo->reserve('parent-no-start', 1, 'tool-no-start', 0, 'child-no-start', 'agent_bbbbbbbbbbbbbbbb', 'worker', 'No start', null, $deadline);
        $this->ensureArtifactReserved('parent-no-start', 'child-no-start', 'agent_bbbbbbbbbbbbbbbb', 'worker', 'No start');

        $row = $repo->findByLifecycleId($launch->lifecycleId);
        $this->assertNotNull($row);
        $this->assertNull($row->startedAt);
        $this->assertNotNull($row->createdAt);
        $this->assertNotNull($row->deadlineAt);
        $expectedSeconds = max(1, $row->deadlineAt->getTimestamp() - $row->createdAt->getTimestamp());

        $commandBus = new TestMessageBus();
        $delivery = $this->buildDeliveryService($commandBus);
        $deferredRepo = self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class);
        $deferredRepo->registerPending($this->correlation($launch->lifecycleId, 'parent-no-start', 'tool-no-start'));

        $clock = new \Symfony\Component\Clock\MockClock($row->deadlineAt->modify('+5 seconds'));
        $interruption = new DeferredSingleSubagentInterruptionService(
            $repo,
            $delivery,
            new RecordingAgentRunner(),
            $deferredRepo,
            new \Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildRunBatchLifecyclePolicyFactory(),
            $commandBus,
            new TestLogger(),
            $clock,
        );
        $interruption->interrupt($launch->lifecycleId, DeferredSubagentInterruptionKindEnum::Timeout);

        $this->assertInstanceOf(CompleteDeferredToolCall::class, $commandBus->messages[0]);
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $entry = $registry->get('parent-no-start', 'agent_bbbbbbbbbbbbbbbb');
        $this->assertSame('Timed out after '.$expectedSeconds.'s.', $entry?->summary);
    }

    public function testParentCancelBeforeRegistrationPersistsIntentWithoutImmediateCancel(): void
    {
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $deadline = new \DateTimeImmutable('+600 seconds');
        $launch = $repo->reserve('parent-pre-reg', 1, 'tool-pre-reg', 0, 'child-pre-reg', 'agent_eeeeeeeeeeeeeeee', 'worker', 'Early cancel', null, $deadline);
        $repo->markLaunched('parent-pre-reg', 'tool-pre-reg', new \DateTimeImmutable());

        $runner = new RecordingAgentRunner();
        $bus = new TestMessageBus();
        $interruption = new DeferredSingleSubagentInterruptionService(
            $repo,
            $this->buildDeliveryService(new TestMessageBus()),
            $runner,
            self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class),
            new \Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildRunBatchLifecyclePolicyFactory(),
            $bus,
            new TestLogger(),
        );
        $interruption->interrupt($launch->lifecycleId, DeferredSubagentInterruptionKindEnum::ParentCancelled);
        $this->assertSame([], $runner->cancelledChildRunIds);

        $row = $repo->findByLifecycleId($launch->lifecycleId);
        $this->assertNotNull($row);
        $this->assertSame(DeferredSubagentInterruptionKindEnum::ParentCancelled, $row->interruptionKind);
    }

    private function ensureArtifactReserved(
        string $parentRunId,
        string $childRunId,
        string $artifactId,
        string $agentName,
        string $task,
    ): void {
        $lifecycle = self::getContainer()->get(ChildRunArtifactLifecycleService::class);
        $lifecycle->ensureReservedPending(new ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: $childRunId,
            artifactId: $artifactId,
            displayName: $agentName,
            taskSummary: $task,
            definitionModel: null,
            artifactKind: AgentArtifactKindEnum::Subagent,
        ));
    }

    private function buildDeliveryService(
        TestMessageBus $commandBus,
        ?SubagentProgressEventAppender $progressEventAppender = null,
    ): DeferredSingleSubagentLifecycleDeliveryService {
        $container = self::getContainer();
        $progressEventAppender ??= new SubagentProgressEventAppender($container->get(CommittedRunEventAppender::class));
        $terminal = new DeferredSingleSubagentTerminalCompletionService(
            launchRepository: $container->get(DeferredSingleSubagentLaunchRepository::class),
            deferredToolCompletionRepository: $container->get(DeferredToolCompletionRepositoryInterface::class),
            progressEventAppender: $progressEventAppender,
            progressSnapshotBuilder: new SubagentProgressSnapshotBuilder(),
            childProgressSummaryBuilder: $container->get(SubagentChildProgressSummaryBuilder::class),
            lifecycleListener: $container->get(SubagentChildRunBatchLifecycleListener::class),
            handoffRenderer: new SubagentChildRunHandoffRenderer(),
            commandBus: $commandBus,
            logger: new TestLogger(),
        );

        return new DeferredSingleSubagentLifecycleDeliveryService(
            launchRepository: $container->get(DeferredSingleSubagentLaunchRepository::class),
            terminalCompletionService: $terminal,
        );
    }

    /**
     * @param list<AfterTurnCommitEventSummary> $events
     */
    private function projectTerminal(
        DeferredSingleSubagentLaunchRepository $repo,
        string $lifecycleId,
        string $childRunId,
        RunStatus $status,
        array $events,
    ): void {
        $handler = new ObserveDeferredSingleSubagentChildTurnHandler($repo, new DeferredChildRunEventProjector(), new TestLogger(), new TestMessageBus());
        $handler(new ObserveDeferredSingleSubagentChildTurnMessage($lifecycleId, $childRunId, $status, 1, $events));
    }

    private function correlation(string $lifecycleId, string $parentRunId, string $toolCallId): DeferredToolCompletionCorrelation
    {
        return new DeferredToolCompletionCorrelation(
            deferredId: $lifecycleId,
            runId: $parentRunId,
            turnNo: 1,
            stepId: 'turn-1-tools-1',
            attempt: 1,
            idempotencyKey: 'idemp',
            toolCallId: $toolCallId,
            toolName: 'subagent',
            arguments: [],
            orderIndex: 0,
        );
    }

    /**
     * @param list<object> $stamps
     */
    private function extractDelayMs(array $stamps): int
    {
        foreach ($stamps as $stamp) {
            if ($stamp instanceof \Symfony\Component\Messenger\Stamp\DelayStamp) {
                return $stamp->getDelay();
            }
        }

        return 0;
    }
}

final class CapturingSubagentProgressEventAppender extends SubagentProgressEventAppender
{
    /** @var list<string> */
    public array $progressStatuses = [];

    public function __construct(CommittedRunEventAppender $committedRunEventAppender)
    {
        parent::__construct($committedRunEventAppender);
    }

    /**
     * @param array<string, mixed> $progress
     */
    public function append(
        string $parentRunId,
        int $parentTurnNo,
        string $parentToolCallId,
        int $parentOrderIndex,
        string $toolName,
        array $progress,
    ): \Ineersa\AgentCore\Domain\Event\RunEvent {
        $status = \is_string($progress['status'] ?? null) ? $progress['status'] : 'unknown';
        $this->progressStatuses[] = $status;

        return parent::append($parentRunId, $parentTurnNo, $parentToolCallId, $parentOrderIndex, $toolName, $progress);
    }
}

final class DelayStampCapturingBus implements \Symfony\Component\Messenger\MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    /** @var list<list<object>> */
    public array $stampSets = [];

    public function dispatch(object $message, array $stamps = []): \Symfony\Component\Messenger\Envelope
    {
        $this->messages[] = $message;
        $this->stampSets[] = $stamps;

        return new \Symfony\Component\Messenger\Envelope($message, $stamps);
    }
}

final class RecordingAgentRunner implements AgentRunnerInterface
{
    /** @var list<string> */
    public array $cancelledChildRunIds = [];
    public ?string $lastReason = null;

    public function start(StartRunInput $input): string
    {
        return $input->runId ?? 'child';
    }

    public function continue(string $runId): void
    {
    }

    public function steer(string $runId, \Ineersa\AgentCore\Domain\Message\AgentMessage $message): void
    {
    }

    public function followUp(string $runId, \Ineersa\AgentCore\Domain\Message\AgentMessage $message): void
    {
    }

    public function appendMessage(string $runId, \Ineersa\AgentCore\Domain\Message\AgentMessage $message): void
    {
    }

    public function cancel(string $runId, ?string $reason = null): void
    {
        $this->cancelledChildRunIds[] = $runId;
        $this->lastReason = $reason;
    }

    public function answerHuman(string $runId, string $questionId, mixed $answer): void
    {
    }

    public function compact(string $runId, ?string $customInstructions = null): void
    {
    }
}
