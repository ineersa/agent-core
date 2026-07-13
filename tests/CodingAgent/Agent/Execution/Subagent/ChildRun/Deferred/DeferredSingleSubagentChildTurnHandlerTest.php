<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\ChildRun\Deferred;

use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSingleSubagentChildEventProjector;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\ObserveDeferredSingleSubagentChildTurnHandler;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\ObserveDeferredSingleSubagentChildTurnMessage;
use Ineersa\CodingAgent\Entity\DeferredSingleSubagentLaunchRepository;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('db')]
final class DeferredSingleSubagentChildTurnHandlerTest extends IsolatedKernelTestCase
{
    public function testHandlerAppliesIncrementalProjectionDuplicateNoOpAndGapUnchanged(): void
    {
        /** @var DeferredSingleSubagentLaunchRepository $repo */
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $deadline = new \DateTimeImmutable('+600 seconds');
        $repo->reserve(
            parentRunId: 'parent-3b1',
            parentTurnNo: 1,
            parentToolCallId: 'tool-3b1',
            parentOrderIndex: 0,
            childRunId: 'child-3b1-uuid',
            artifactId: 'agent_aaaaaaaaaaaaaaaa',
            agentName: 'worker',
            task: 'task',
            definitionModel: 'model-x',
            deadlineAt: $deadline,
        );
        $repo->markLaunched('parent-3b1', 'tool-3b1', new \DateTimeImmutable());
        $launch = $repo->findByParentRunAndToolCall('parent-3b1', 'tool-3b1');
        $this->assertNotNull($launch);

        $logger = new TestLogger();
        $handler = new ObserveDeferredSingleSubagentChildTurnHandler(
            $repo,
            new DeferredSingleSubagentChildEventProjector(),
            $logger,
        );

        $batch1 = [
            new AfterTurnCommitEventSummary(1, RunEventTypeEnum::RunStarted->value, ['payload' => ['metadata' => ['model' => 'model-x', 'provider' => 'openai', 'context_window' => 200000]]]),
            new AfterTurnCommitEventSummary(2, RunEventTypeEnum::LlmStepCompleted->value, [
                'usage' => ['input_tokens' => 40, 'output_tokens' => 5, 'total_tokens' => 45],
                'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Hi']]],
            ]),
        ];
        $handler(new ObserveDeferredSingleSubagentChildTurnMessage(
            lifecycleId: $launch->lifecycleId,
            childRunId: 'child-3b1-uuid',
            committedStatus: RunStatus::Running->value,
            turnNo: 1,
            committedEvents: $batch1,
        ));

        $entity = $repo->findEntityByLifecycleId($launch->lifecycleId);
        $this->assertNotNull($entity);
        $this->assertSame(2, $entity->childEventCursor);
        $this->assertIsArray($entity->childLifecycleProjection);
        $this->assertSame(40, $entity->childLifecycleProjection['input_tokens']);

        $batch2 = [
            new AfterTurnCommitEventSummary(3, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']),
        ];
        $handler(new ObserveDeferredSingleSubagentChildTurnMessage(
            lifecycleId: $launch->lifecycleId,
            childRunId: 'child-3b1-uuid',
            committedStatus: RunStatus::Completed->value,
            turnNo: 1,
            committedEvents: $batch2,
        ));
        $entity = $repo->findEntityByLifecycleId($launch->lifecycleId);
        $this->assertSame(3, $entity->childEventCursor);
        $this->assertSame('completed', $entity->childLifecycleProjection['child_status']);

        $handler(new ObserveDeferredSingleSubagentChildTurnMessage(
            lifecycleId: $launch->lifecycleId,
            childRunId: 'child-3b1-uuid',
            committedStatus: RunStatus::Completed->value,
            turnNo: 1,
            committedEvents: $batch2,
        ));
        $entityDup = $repo->findEntityByLifecycleId($launch->lifecycleId);
        $this->assertSame(3, $entityDup->childEventCursor);

        $handler(new ObserveDeferredSingleSubagentChildTurnMessage(
            lifecycleId: $launch->lifecycleId,
            childRunId: 'child-3b1-uuid',
            committedStatus: RunStatus::Running->value,
            turnNo: 2,
            committedEvents: [new AfterTurnCommitEventSummary(5, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2])],
        ));
        $entityGap = $repo->findEntityByLifecycleId($launch->lifecycleId);
        $this->assertSame(3, $entityGap->childEventCursor);
        $this->assertContains('deferred_single_subagent.child_event_gap', array_column($logger->records, 'message'));
    }
}
