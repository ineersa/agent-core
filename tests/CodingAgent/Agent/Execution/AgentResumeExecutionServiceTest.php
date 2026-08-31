<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Application\Dto\RunStateReplayResult;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\AgentDepthGuard;
use Ineersa\CodingAgent\Agent\Execution\AgentResumeExecutionService;
use Ineersa\CodingAgent\Agent\Execution\AgentResumeTaskDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\RunStartedMetadataReader;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Entity\DeferredSubagentChild;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Tests\Support\StubRunRelationshipReader;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Focused eligibility coverage for agent_resume (no deferred wait / messenger loop).
 */
#[CoversClass(AgentResumeExecutionService::class)]
final class AgentResumeExecutionServiceTest extends IsolatedKernelTestCase
{
    public function testRejectsUnknownArtifact(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Unknown artifact_id "agent_missing"');

        $this->resume(
            parentRunId: 'parent-unknown',
            tasks: [new AgentResumeTaskDTO(artifact_id: 'agent_missing', task: 'continue')],
        );
    }

    public function testRejectsForkArtifactKind(): void
    {
        $parent = 'parent-fork-kind';
        $artifactId = 'agent_fork_kind';
        $childRunId = 'child-fork-kind';
        $this->registry()->create($parent, $artifactId, $childRunId, 'fork', AgentArtifactKindEnum::Fork);
        $this->registry()->update($parent, $artifactId, status: AgentArtifactStatusEnum::Completed, summary: 'done');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('cannot resume fork children');

        $this->resume(
            parentRunId: $parent,
            tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue')],
            childRunId: $childRunId,
        );
    }

    public function testRejectsForkChildKindFromMetadata(): void
    {
        $parent = 'parent-fork-meta';
        $artifactId = 'agent_fork_meta';
        $childRunId = 'child-fork-meta';
        $this->registry()->create($parent, $artifactId, $childRunId, 'scout', AgentArtifactKindEnum::Subagent);
        $this->registry()->update($parent, $artifactId, status: AgentArtifactStatusEnum::Completed, summary: 'done');

        $eventStore = $this->createStub(EventStoreInterface::class);
        $eventStore->method('firstFor')->willReturnCallback(static function (string $runId) use ($childRunId, $artifactId, $parent): ?RunEvent {
            if ($runId !== $childRunId) {
                return null;
            }

            return new RunEvent(
                runId: $childRunId,
                seq: 1,
                turnNo: 0,
                type: RunEventTypeEnum::RunStarted->value,
                payload: [
                    'step_id' => 's',
                    'payload' => [
                        'metadata' => [
                            'session' => [
                                'kind' => 'agent_child',
                                'child_kind' => 'fork',
                                'parent_run_id' => $parent,
                                'agent_name' => 'fork',
                                'artifact_id' => $artifactId,
                            ],
                            'model' => 'test/model',
                            'reasoning' => 'medium',
                            'tools_scope' => ['allowed_tools' => ['bash']],
                        ],
                    ],
                ],
            );
        });

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('cannot resume fork children');

        $this->resume(
            parentRunId: $parent,
            tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue')],
            childRunId: $childRunId,
            eventStore: $eventStore,
        );
    }

    public function testRejectsInFlightRunningArtifact(): void
    {
        $parent = 'parent-running';
        $artifactId = 'agent_running';
        $childRunId = 'child-running';
        $this->registry()->create($parent, $artifactId, $childRunId, 'scout', AgentArtifactKindEnum::Subagent);
        $this->registry()->update($parent, $artifactId, status: AgentArtifactStatusEnum::Running);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('already in flight (status=running)');

        $this->resume(
            parentRunId: $parent,
            tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue')],
            childRunId: $childRunId,
        );
    }

    public function testRejectsNeedsClarificationArtifact(): void
    {
        $parent = 'parent-clarify';
        $artifactId = 'agent_clarify';
        $childRunId = 'child-clarify';
        $this->registry()->create($parent, $artifactId, $childRunId, 'scout', AgentArtifactKindEnum::Subagent);
        $this->registry()->update(
            $parent,
            $artifactId,
            status: AgentArtifactStatusEnum::NeedsClarification,
            needsClarification: 'need answer',
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('already in flight (status=needs_clarification)');

        $this->resume(
            parentRunId: $parent,
            tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue')],
            childRunId: $childRunId,
        );
    }

    public function testRejectsChildMidCancellationFromCanonicalReplay(): void
    {
        $parent = 'parent-mid-cancel';
        $artifactId = 'agent-mid-cancel';
        $childRunId = 'child-mid-cancel';
        $this->seedTerminalChild($parent, $artifactId, $childRunId, latestInputTokens: 10, contextWindow: 200_000);
        $runStateRebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $runStateRebuilder->expects($this->once())
            ->method('rebuildIfStale')
            ->with($this->isInstanceOf(RunState::class), $childRunId)
            ->willReturn(RunStateReplayResult::rebuilt(new RunState(runId: $childRunId, status: RunStatus::Cancelling), 1, 1, true));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('mid-cancel and cannot be resumed yet');

        $this->resume(
            parentRunId: $parent,
            tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue')],
            childRunId: $childRunId,
            runStateRebuilder: $runStateRebuilder,
        );
    }

    public function testRejectsOversizeContextWithMissingWindowFloor(): void
    {
        $parent = 'parent-oversize-floor';
        $artifactId = 'agent_oversize_floor';
        $childRunId = 'child-oversize-floor';
        $this->seedTerminalChild($parent, $artifactId, $childRunId, latestInputTokens: 200_000, contextWindow: null);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('child context is near the limit');

        $this->resume(
            parentRunId: $parent,
            tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue')],
            childRunId: $childRunId,
            runStatus: RunStatus::Completed,
        );
    }

    public function testRejectsOversizeContextAtSeventyFivePercentOfWindow(): void
    {
        $parent = 'parent-oversize-window';
        $artifactId = 'agent_oversize_window';
        $childRunId = 'child-oversize-window';
        $this->seedTerminalChild($parent, $artifactId, $childRunId, latestInputTokens: 300_000, contextWindow: 400_000);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('threshold 300000');

        $this->resume(
            parentRunId: $parent,
            tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue')],
            childRunId: $childRunId,
            runStatus: RunStatus::Completed,
        );
    }

    public function testResumesDistinctFailedAndCancelledArtifactsInParallelViaExistingChildRuns(): void
    {
        $parent = 'parent-parallel';
        $failedArtifactId = 'agent_failed';
        $failedChildRunId = 'child-failed';
        $cancelledArtifactId = 'agent_cancelled';
        $cancelledChildRunId = 'child-cancelled';
        $this->seedTerminalChild($parent, $failedArtifactId, $failedChildRunId, latestInputTokens: 10, contextWindow: 200_000, artifactStatus: AgentArtifactStatusEnum::Failed);
        $this->seedTerminalChild($parent, $cancelledArtifactId, $cancelledChildRunId, latestInputTokens: 10, contextWindow: 200_000, artifactStatus: AgentArtifactStatusEnum::Cancelled);

        $followUps = [];
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->exactly(2))
            ->method('followUp')
            ->willReturnCallback(static function (string $runId, AgentMessage $message) use (&$followUps): void {
                $followUps[$runId] = $message;
            });

        $this->resume(
            parentRunId: $parent,
            tasks: [
                new AgentResumeTaskDTO(artifact_id: $failedArtifactId, task: 'verify failure fix'),
                new AgentResumeTaskDTO(artifact_id: $cancelledArtifactId, task: 'continue cancellation-safe work'),
            ],
            agentRunner: $agentRunner,
            toolCallId: 'tc-parallel',
            executionMode: ChildRunBatchExecutionModeEnum::Parallel,
        );

        $this->assertSame('verify failure fix', $followUps[$failedChildRunId]->content[0]['text']);
        $this->assertSame('continue cancellation-safe work', $followUps[$cancelledChildRunId]->content[0]['text']);
        $this->assertSame(AgentArtifactStatusEnum::Running, $this->registry()->get($parent, $failedArtifactId)?->status);
        $this->assertSame(AgentArtifactStatusEnum::Running, $this->registry()->get($parent, $cancelledArtifactId)?->status);

        $batch = self::getContainer()->get(DeferredSubagentBatchRepository::class)
            ->findByParentRunAndToolCall($parent, 'tc-parallel');
        $this->assertNotNull($batch);
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Launched, $batch->launchStatus);
    }

    public function testRejectsNestedParentCaller(): void
    {
        $parent = 'parent-nested';
        $artifactId = 'agent_nested';
        $childRunId = 'child-nested';
        $this->registry()->create($parent, $artifactId, $childRunId, 'scout', AgentArtifactKindEnum::Subagent);
        $this->registry()->update($parent, $artifactId, status: AgentArtifactStatusEnum::Completed, summary: 'done');

        $parentEventStore = $this->createStub(EventStoreInterface::class);
        $parentEventStore->method('firstFor')->willReturnCallback(static function (string $runId) use ($parent): ?RunEvent {
            if ($runId !== $parent) {
                return null;
            }

            return new RunEvent(
                runId: $parent,
                seq: 1,
                turnNo: 0,
                type: RunEventTypeEnum::RunStarted->value,
                payload: [
                    'step_id' => 's',
                    'payload' => [
                        'metadata' => [
                            'session' => [
                                'kind' => 'agent_child',
                                'parent_run_id' => 'grandparent',
                                'agent_name' => 'scout',
                                'artifact_id' => 'agent_parent',
                            ],
                            'model' => 'test/model',
                            'reasoning' => 'medium',
                            'tools_scope' => ['allowed_tools' => []],
                        ],
                    ],
                ],
            );
        });

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('is an agent child; nested launches are not supported');

        $this->resume(
            parentRunId: $parent,
            tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue')],
            childRunId: $childRunId,
            eventStore: $parentEventStore,
            relationshipReader: StubRunRelationshipReader::child($parent, 'grandparent'),
        );
    }

    public function testFollowUpFailureMarksBatchFailedAndRevertsChildToPriorTerminal(): void
    {
        $parent = 'parent-followup-fail';
        $artifactId = 'agent_followup_fail';
        $childRunId = 'child-followup-fail';
        $toolCallId = 'tc-followup-fail';
        $this->seedTerminalChild($parent, $artifactId, $childRunId, latestInputTokens: 10, contextWindow: 200_000);

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())
            ->method('followUp')
            ->willThrowException(new \RuntimeException('follow_up boom'));

        try {
            $this->resume(
                parentRunId: $parent,
                tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue')],
                childRunId: $childRunId,
                runStatus: RunStatus::Completed,
                agentRunner: $agentRunner,
                toolCallId: $toolCallId,
            );
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('failed to follow_up', $e->getMessage());
        }

        $batch = self::getContainer()->get(DeferredSubagentBatchRepository::class)
            ->findByParentRunAndToolCall($parent, $toolCallId);
        $this->assertNotNull($batch);
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Failed, $batch->launchStatus);

        $entry = $this->registry()->get($parent, $artifactId);
        $this->assertNotNull($entry);
        // followUp failure reverts the Running mark back to the prior terminal status.
        $this->assertSame(AgentArtifactStatusEnum::Completed, $entry->status);

        $this->expectException(ToolCallException::class);
        // Same tool_call_id still short-circuits on the previously failed batch.
        $this->expectExceptionMessage('batch launch previously failed');
        $this->resume(
            parentRunId: $parent,
            tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue again')],
            childRunId: $childRunId,
            runStatus: RunStatus::Completed,
            toolCallId: $toolCallId,
        );
    }

    public function testLaunchSuccessPersistenceFailureLeavesDeferredResumeRecoverable(): void
    {
        $parent = 'parent-launch-success-persist-failure';
        $artifactId = 'agent_launch_success_persist_failure';
        $childRunId = 'child-launch-success-persist-failure';
        $toolCallId = 'tc-launch-success-persist-failure';
        $this->seedTerminalChild($parent, $artifactId, $childRunId, latestInputTokens: 10, contextWindow: 200_000);

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('followUp')->willReturnCallback(
            static function () use ($childRunId): void {
                self::getContainer()->get('doctrine')->getManager()->getConnection()->executeStatement(
                    'DELETE FROM deferred_subagent_child WHERE child_run_id = :child_run_id',
                    ['child_run_id' => $childRunId],
                );
            },
        );
        $logger = new TestLogger();

        $outcome = $this->resume(
            parentRunId: $parent,
            tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue')],
            childRunId: $childRunId,
            agentRunner: $agentRunner,
            toolCallId: $toolCallId,
            logger: $logger,
        );

        $this->assertNotSame('', $outcome->deferredId);
        $batch = self::getContainer()->get(DeferredSubagentBatchRepository::class)
            ->findByParentRunAndToolCall($parent, $toolCallId);
        $this->assertNotNull($batch);
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Reserved, $batch->launchStatus);
        $this->assertSame('agent_resume.launch_success_persist_failed', $logger->records[0]['message'] ?? null);
        $this->assertSame($parent, $logger->records[0]['context']['session_id'] ?? null);
        $this->assertSame($toolCallId, $logger->records[0]['context']['tool_call_id'] ?? null);
    }

    public function testArtifactCanResumeDuringCurrentParentLifetimeButNotAfterAttach(): void
    {
        $parent = 'parent-lifetime';
        $artifactId = 'agent_lifetime';
        $childRunId = 'child-lifetime';
        $this->seedTerminalChild($parent, $artifactId, $childRunId, latestInputTokens: 10, contextWindow: 200_000);

        $this->resume(
            parentRunId: $parent,
            tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue')],
            childRunId: $childRunId,
            toolCallId: 'tc-lifetime-before-attach',
        );
        $this->registry()->update($parent, $artifactId, status: AgentArtifactStatusEnum::Completed);

        self::getContainer()->get(InProcessAgentSessionClient::class)->attach($parent);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('belongs to a previous parent lifetime');
        $this->resume(
            parentRunId: $parent,
            tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue after attach')],
            childRunId: $childRunId,
            toolCallId: 'tc-lifetime-after-attach',
        );
    }

    public function testRejectsDuplicateResolvedArtifactViaMixedIdentifiers(): void
    {
        $parent = 'parent-dup-mixed';
        $artifactId = 'agent_dup_mixed';
        $childRunId = 'child-dup-mixed';
        $this->seedTerminalChild($parent, $artifactId, $childRunId, latestInputTokens: 10, contextWindow: 200_000);

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->never())->method('followUp');
        $runStateRebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $runStateRebuilder->expects($this->once())
            ->method('rebuildIfStale')
            ->with(
                $this->callback(static fn (RunState $state): bool => $childRunId === $state->runId),
                $childRunId,
            )
            ->willReturn(RunStateReplayResult::rebuilt(new RunState(runId: $childRunId, status: RunStatus::Completed), 1, 1, true));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(\sprintf('Duplicate artifact_id "%s" in one agent_resume call.', $artifactId));

        $this->resume(
            parentRunId: $parent,
            tasks: [
                new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue via artifact'),
                new AgentResumeTaskDTO(agent_run_id: $childRunId, task: 'continue via run id'),
            ],
            childRunId: $childRunId,
            agentRunner: $agentRunner,
            executionMode: ChildRunBatchExecutionModeEnum::Parallel,
            runStateRebuilder: $runStateRebuilder,
        );
    }

    /**
     * @param list<AgentResumeTaskDTO> $tasks
     */
    private function resume(
        string $parentRunId,
        array $tasks,
        ?string $childRunId = null,
        ?EventStoreInterface $eventStore = null,
        RunStatus $runStatus = RunStatus::Completed,
        ?AgentRunnerInterface $agentRunner = null,
        string $toolCallId = 'tc-resume-1',
        ChildRunBatchExecutionModeEnum $executionMode = ChildRunBatchExecutionModeEnum::Single,
        ?TestLogger $logger = null,
        ?RunStateRebuilderInterface $runStateRebuilder = null,
        ?\Ineersa\CodingAgent\Repository\RunRelationshipReaderInterface $relationshipReader = null,
    ): \Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome {
        $contextAccessor = new StackToolExecutionContextAccessor();
        if (null === $runStateRebuilder) {
            $runStateRebuilder = $this->createStub(RunStateRebuilderInterface::class);
            $runStateRebuilder->method('rebuildIfStale')->willReturnCallback(
                static function (RunState $state, string $runId) use ($runStatus): RunStateReplayResult {
                    return RunStateReplayResult::rebuilt(new RunState(runId: $runId, status: $runStatus), 1, 1, true);
                },
            );
        }

        $eventStore ??= $this->createStub(EventStoreInterface::class);
        $metadataReader = new RunStartedMetadataReader(
            $eventStore,
            AttributeSerializerValidatorTestFactory::denormalizer(),
        );

        $service = new AgentResumeExecutionService(
            artifactRegistry: $this->registry(),
            batchRepository: self::getContainer()->get(DeferredSubagentBatchRepository::class),
            childRepository: self::getContainer()->get(DeferredSubagentChildRepository::class),
            identityFactory: new DeferredSubagentBatchIdentityFactory(),
            agentRunner: $agentRunner ?? $this->createStub(AgentRunnerInterface::class),
            runStateRebuilder: $runStateRebuilder,
            metadataReader: $metadataReader,
            relationshipReader: $relationshipReader ?? StubRunRelationshipReader::topLevel($parentRunId),
            depthGuard: new AgentDepthGuard(),
            contextAccessor: $contextAccessor,
            agentsConfig: new AgentsConfig(maxAgents: 4),
            logger: $logger ?? new NullLogger(),
        );

        return $contextAccessor->with(
            new ToolContext(
                runId: $parentRunId,
                turnNo: 1,
                toolCallId: $toolCallId,
                toolName: 'agent_resume',
                cancellationToken: new NullCancellationToken(),
                timeoutSeconds: 30,
                orderIndex: 0,
                parentModel: 'test/model',
            ),
            static function () use ($service, $parentRunId, $tasks, $executionMode): \Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome {
                return $service->resume($parentRunId, $tasks, $executionMode);
            },
        );
    }

    private function seedTerminalChild(
        string $parent,
        string $artifactId,
        string $childRunId,
        int $latestInputTokens,
        ?int $contextWindow,
        AgentArtifactStatusEnum $artifactStatus = AgentArtifactStatusEnum::Completed,
    ): void {
        $this->registry()->create($parent, $artifactId, $childRunId, 'scout', AgentArtifactKindEnum::Subagent);
        $this->registry()->update($parent, $artifactId, status: $artifactStatus, summary: 'done');

        /** @var SerializerInterface $serializer */
        $serializer = self::getContainer()->get(SerializerInterface::class);
        $projection = new DeferredChildRunLifecycleProjectionDTO(
            childStatus: RunStatus::Completed,
            childTurnNo: 2,
            lastCommittedSeq: 5,
            model: 'test/model',
            reasoning: 'medium',
            latestInputTokens: $latestInputTokens,
            contextWindow: $contextWindow,
        );
        $projectionArray = $serializer->normalize(
            $projection,
            null,
            [AbstractObjectNormalizer::SKIP_NULL_VALUES => true],
        );
        if (!\is_array($projectionArray)) {
            throw new \RuntimeException('Expected normalized lifecycle projection array.');
        }

        $em = self::getContainer()->get('doctrine')->getManager();
        $row = new DeferredSubagentChild();
        $row->batchLifecycleId = 'batch-'.$childRunId;
        $row->batchIndex = 1;
        $row->childRunId = $childRunId;
        $row->artifactId = $artifactId;
        $row->agentName = 'scout';
        $row->task = 'seed';
        $row->launchModel = 'test/model';
        $row->launchReasoning = 'medium';
        $row->launchStatus = DeferredSubagentChildLaunchStatusEnum::Launched;
        $row->childEventCursor = 5;
        $row->childLifecycleProjection = $projectionArray;
        $row->terminalCompletedAt = new \DateTimeImmutable('2026-08-21T00:01:00Z');
        $row->terminalStatus = 'completed';
        $em->persist($row);
        $em->flush();
    }

    private function registry(): AgentArtifactRegistry
    {
        return self::getContainer()->get(AgentArtifactRegistry::class);
    }
}
