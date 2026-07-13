<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\ChildRun\Deferred;

use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Event\DeferredToolCompletionRegisteredEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSingleSubagentChildEventProjector;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSingleSubagentLifecycleDeliveryService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredToolCompletionRegisteredSubagentListener;
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

        $this->assertCount(1, $bus->messages);
        $this->assertSame($projection->lifecycleId, $bus->messages[0]->lifecycleId);
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
            new DeferredSingleSubagentChildEventProjector(),
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

    private function buildDeliveryService(TestMessageBus $commandBus): DeferredSingleSubagentLifecycleDeliveryService
    {
        $container = self::getContainer();

        return new DeferredSingleSubagentLifecycleDeliveryService(
            launchRepository: $container->get(DeferredSingleSubagentLaunchRepository::class),
            deferredToolCompletionRepository: $container->get(DeferredToolCompletionRepositoryInterface::class),
            progressEventAppender: new SubagentProgressEventAppender($container->get(CommittedRunEventAppender::class)),
            progressSnapshotBuilder: new SubagentProgressSnapshotBuilder(),
            childProgressSummaryBuilder: $container->get(SubagentChildProgressSummaryBuilder::class),
            lifecycleListener: $container->get(SubagentChildRunBatchLifecycleListener::class),
            handoffRenderer: new SubagentChildRunHandoffRenderer(),
            commandBus: $commandBus,
            logger: new TestLogger(),
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
        $handler = new ObserveDeferredSingleSubagentChildTurnHandler($repo, new DeferredSingleSubagentChildEventProjector(), new TestLogger(), new TestMessageBus());
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
}
