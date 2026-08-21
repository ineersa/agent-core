<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\AgentDepthGuard;
use Ineersa\CodingAgent\Agent\Execution\AgentResumeExecutionService;
use Ineersa\CodingAgent\Agent\Execution\AgentResumeTaskDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Entity\DeferredSubagentChild;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
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
        $eventStore->method('allFor')->willReturnCallback(static function (string $runId) use ($childRunId, $artifactId, $parent): array {
            if ($runId !== $childRunId) {
                return [];
            }

            return [
                new RunEvent(
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
                ),
            ];
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

    public function testRejectsNestedParentCaller(): void
    {
        $parent = 'parent-nested';
        $artifactId = 'agent_nested';
        $childRunId = 'child-nested';
        $this->registry()->create($parent, $artifactId, $childRunId, 'scout', AgentArtifactKindEnum::Subagent);
        $this->registry()->update($parent, $artifactId, status: AgentArtifactStatusEnum::Completed, summary: 'done');

        $parentEventStore = $this->createStub(EventStoreInterface::class);
        $parentEventStore->method('allFor')->willReturnCallback(static function (string $runId) use ($parent): array {
            if ($runId !== $parent) {
                return [];
            }

            return [
                new RunEvent(
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
                ),
            ];
        });

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Nested subagent launches are not supported');

        $this->resume(
            parentRunId: $parent,
            tasks: [new AgentResumeTaskDTO(artifact_id: $artifactId, task: 'continue')],
            childRunId: $childRunId,
            eventStore: $parentEventStore,
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
    ): void {
        $contextAccessor = new StackToolExecutionContextAccessor();
        $runStore = $this->createStub(RunStoreInterface::class);
        if (null !== $childRunId) {
            $runStore->method('get')->willReturn(new RunState(runId: $childRunId, status: $runStatus));
        }

        $eventStore ??= $this->createStub(EventStoreInterface::class);
        $metadataReader = new SubagentRunMetadataReader(
            $eventStore,
            AttributeSerializerValidatorTestFactory::denormalizer(),
        );

        $service = new AgentResumeExecutionService(
            artifactRegistry: $this->registry(),
            batchRepository: self::getContainer()->get(DeferredSubagentBatchRepository::class),
            childRepository: self::getContainer()->get(DeferredSubagentChildRepository::class),
            identityFactory: new DeferredSubagentBatchIdentityFactory(),
            agentRunner: $this->createStub(AgentRunnerInterface::class),
            runStore: $runStore,
            metadataReader: $metadataReader,
            depthGuard: new AgentDepthGuard(),
            contextAccessor: $contextAccessor,
            agentsConfig: new AgentsConfig(maxAgents: 4),
        );

        $contextAccessor->with(
            new ToolContext(
                runId: $parentRunId,
                turnNo: 1,
                toolCallId: 'tc-resume-1',
                toolName: 'agent_resume',
                cancellationToken: new NullCancellationToken(),
                timeoutSeconds: 30,
                orderIndex: 0,
                parentModel: 'test/model',
            ),
            static function () use ($service, $parentRunId, $tasks): void {
                $service->resume($parentRunId, $tasks, ChildRunBatchExecutionModeEnum::Single);
            },
        );
    }

    private function seedTerminalChild(
        string $parent,
        string $artifactId,
        string $childRunId,
        int $latestInputTokens,
        ?int $contextWindow,
    ): void {
        $this->registry()->create($parent, $artifactId, $childRunId, 'scout', AgentArtifactKindEnum::Subagent);
        $this->registry()->update($parent, $artifactId, status: AgentArtifactStatusEnum::Completed, summary: 'done');

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
