<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\ChildRun\Deferred;

use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
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
            new TestMessageBus(),
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
            committedStatus: RunStatus::Running,
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
            committedStatus: RunStatus::Completed,
            turnNo: 1,
            committedEvents: $batch2,
        ));
        $entity = $repo->findEntityByLifecycleId($launch->lifecycleId);
        $this->assertSame(3, $entity->childEventCursor);
        $this->assertSame('completed', $entity->childLifecycleProjection['child_status']);

        $handler(new ObserveDeferredSingleSubagentChildTurnMessage(
            lifecycleId: $launch->lifecycleId,
            childRunId: 'child-3b1-uuid',
            committedStatus: RunStatus::Completed,
            turnNo: 1,
            committedEvents: $batch2,
        ));
        $entityDup = $repo->findEntityByLifecycleId($launch->lifecycleId);
        $this->assertSame(3, $entityDup->childEventCursor);

        $handler(new ObserveDeferredSingleSubagentChildTurnMessage(
            lifecycleId: $launch->lifecycleId,
            childRunId: 'child-3b1-uuid',
            committedStatus: RunStatus::Running,
            turnNo: 2,
            committedEvents: [new AfterTurnCommitEventSummary(5, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2])],
        ));
        $entityGap = $repo->findEntityByLifecycleId($launch->lifecycleId);
        $this->assertSame(3, $entityGap->childEventCursor);
        $this->assertContains('deferred_single_subagent.child_event_gap', array_column($logger->records, 'message'));
    }

    public function testProjectionPrivacyStatusAndFullAssistantText(): void
    {
        /** @var DeferredSingleSubagentLaunchRepository $repo */
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $deadline = new \DateTimeImmutable('+600 seconds');
        $repo->reserve(
            parentRunId: 'parent-privacy',
            parentTurnNo: 1,
            parentToolCallId: 'tool-privacy',
            parentOrderIndex: 0,
            childRunId: 'child-privacy-uuid',
            artifactId: 'agent_cccccccccccccccc',
            agentName: 'worker',
            task: 'task',
            definitionModel: null,
            deadlineAt: $deadline,
        );
        $repo->markLaunched('parent-privacy', 'tool-privacy', new \DateTimeImmutable());
        $launch = $repo->findByParentRunAndToolCall('parent-privacy', 'tool-privacy');
        $this->assertNotNull($launch);

        $handler = new ObserveDeferredSingleSubagentChildTurnHandler(
            $repo,
            new DeferredSingleSubagentChildEventProjector(),
            new TestLogger(),
            new TestMessageBus(),
        );

        $longText = str_repeat('Z', 300);
        $secretArgs = json_encode(['path' => '/safe/path.php', 'api_key' => 'super-secret', 'new_string' => 'leak'], \JSON_THROW_ON_ERROR);
        $batch = [
            new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, [
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
                'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => $longText]]],
            ]),
            new AfterTurnCommitEventSummary(2, RunEventTypeEnum::LlmStepCompleted->value, [
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => '']],
                    'tool_calls' => [['id' => 'tc1', 'name' => 'read', 'arguments' => $secretArgs]],
                ],
            ]),
            new AfterTurnCommitEventSummary(3, RunEventTypeEnum::ToolExecutionEnd->value, ['tool_call_id' => 'tc1']),
        ];
        $handler(new ObserveDeferredSingleSubagentChildTurnMessage(
            lifecycleId: $launch->lifecycleId,
            childRunId: 'child-privacy-uuid',
            committedStatus: RunStatus::WaitingHuman,
            turnNo: 4,
            committedEvents: $batch,
        ));

        $entity = $repo->findEntityByLifecycleId($launch->lifecycleId);
        $this->assertNotNull($entity);
        $this->assertNotEmpty($entity->childLifecycleProjection['recent_tools']);
        $this->assertStringContainsString('safe/path.php', (string) $entity->childLifecycleProjection['recent_tools'][0]);
        $json = json_encode($entity->childLifecycleProjection, \JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('super-secret', $json);
        $this->assertStringNotContainsString('leak', $json);
        $this->assertStringNotContainsString('"args"', $json);
        $this->assertSame('waiting_human', $entity->childLifecycleProjection['child_status']);
        $this->assertSame(4, $entity->childLifecycleProjection['child_turn_no']);
        $this->assertSame($longText, $entity->childLifecycleProjection['assistant_result_text']);
        $this->assertLessThanOrEqual(220, mb_strlen((string) $entity->childLifecycleProjection['assistant_excerpt']));

        $handler(new ObserveDeferredSingleSubagentChildTurnMessage(
            lifecycleId: $launch->lifecycleId,
            childRunId: 'child-privacy-uuid',
            committedStatus: RunStatus::Running,
            turnNo: 5,
            committedEvents: [new AfterTurnCommitEventSummary(4, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 5])],
        ));
        $entity = $repo->findEntityByLifecycleId($launch->lifecycleId);
        $this->assertSame('running', $entity->childLifecycleProjection['child_status']);
        $this->assertSame(5, $entity->childLifecycleProjection['child_turn_no']);

        $malformed = new AfterTurnCommitEventSummary(5, RunEventTypeEnum::LlmStepCompleted->value, [
            'assistant_message' => [
                'role' => 'assistant',
                'tool_calls' => [['id' => 'tc2', 'name' => 'grep', 'arguments' => '{not-json']],
            ],
        ]);
        $handler(new ObserveDeferredSingleSubagentChildTurnMessage(
            lifecycleId: $launch->lifecycleId,
            childRunId: 'child-privacy-uuid',
            committedStatus: RunStatus::Compacting,
            turnNo: 5,
            committedEvents: [$malformed],
        ));
        $entity = $repo->findEntityByLifecycleId($launch->lifecycleId);
        $this->assertSame('compacting', $entity->childLifecycleProjection['child_status']);
        $this->assertStringContainsString('grep', json_encode($entity->childLifecycleProjection, \JSON_THROW_ON_ERROR));

        $handler(new ObserveDeferredSingleSubagentChildTurnMessage(
            lifecycleId: $launch->lifecycleId,
            childRunId: 'child-privacy-uuid',
            committedStatus: RunStatus::Cancelling,
            turnNo: 6,
            committedEvents: [new AfterTurnCommitEventSummary(6, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 6])],
        ));
        $entity = $repo->findEntityByLifecycleId($launch->lifecycleId);
        $this->assertSame('cancelling', $entity->childLifecycleProjection['child_status']);
        $this->assertSame(6, $entity->childLifecycleProjection['child_turn_no']);
    }

    public function testOptimisticProjectionVersionPreventsStaleCursorRegression(): void
    {
        /** @var DeferredSingleSubagentLaunchRepository $repo */
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $repo->reserve(
            parentRunId: 'parent-lock',
            parentTurnNo: 1,
            parentToolCallId: 'tool-lock',
            parentOrderIndex: 0,
            childRunId: 'child-lock-uuid',
            artifactId: 'agent_dddddddddddddddd',
            agentName: 'worker',
            task: 'task',
            definitionModel: null,
            deadlineAt: new \DateTimeImmutable('+600 seconds'),
        );
        $repo->markLaunched('parent-lock', 'tool-lock', new \DateTimeImmutable());
        $launch = $repo->findByParentRunAndToolCall('parent-lock', 'tool-lock');
        $this->assertNotNull($launch);
        $entity = $repo->findEntityByLifecycleId($launch->lifecycleId);
        $this->assertNotNull($entity);
        $versionBeforeApply = $entity->projectionVersion;

        $projection = new \Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSingleSubagentChildLifecycleProjectionDTO(
            childStatus: RunStatus::Running,
            childTurnNo: 1,
            lastCommittedSeq: 1,
        );

        $repo->applyChildLifecycleProjection($launch->lifecycleId, $projection, 1, $versionBeforeApply);
        $entity = $repo->findEntityByLifecycleId($launch->lifecycleId);
        $this->assertGreaterThan($versionBeforeApply, $entity->projectionVersion);
        $this->assertSame(1, $entity->childEventCursor);

        $this->expectException(\Doctrine\ORM\OptimisticLockException::class);
        $repo->applyChildLifecycleProjection($launch->lifecycleId, $projection, 99, $versionBeforeApply);
    }
}
