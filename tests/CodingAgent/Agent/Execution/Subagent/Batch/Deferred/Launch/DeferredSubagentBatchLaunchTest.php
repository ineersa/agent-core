<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\Batch\Deferred\Launch;

use Ineersa\AgentCore\Application\Dto\RunStateReplayResult;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\SubagentExecutionService;
use Ineersa\CodingAgent\Agent\Execution\SubagentLaunchPreparationService;
use Ineersa\CodingAgent\Agent\Execution\SubagentTaskDTO;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Entity\DeferredSubagentChild;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Session\SessionAgentArtifactPathResolver;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Normalized deferred batch launch and production executeParallel cutover (Piece 4C2).
 */
#[CoversClass(DeferredSubagentBatchLaunchService::class)]
#[CoversClass(SubagentExecutionService::class)]
#[Group('db')]
final class DeferredSubagentBatchLaunchTest extends IsolatedKernelTestCase
{
    public function testOrderedTwoChildBatchLaunchPreservesParallelModeAndStartOrder(): void
    {
        $parentRunId = 'parent-batch-4a-1';
        $toolCallId = 'call-batch-4a-1';
        $identityFactory = new DeferredSubagentBatchIdentityFactory();
        $lifecycleId = $identityFactory->batchLifecycleId($parentRunId, $toolCallId);
        $childOne = $identityFactory->childIdentity($parentRunId, $toolCallId, 1);
        $childTwo = $identityFactory->childIdentity($parentRunId, $toolCallId, 2);

        $startOrder = [];
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->exactly(2))->method('start')->willReturnCallback(static function ($input) use (&$startOrder): string {
            $startOrder[] = $input->runId;

            return $input->runId;
        });

        $batchLaunch = $this->buildBatchLaunchService($agentRunner, [
            $this->parallelDefinition('batch-a'),
            $this->parallelDefinition('batch-b'),
        ]);
        $execution = new SubagentExecutionService($batchLaunch);

        $outcome = $this->withToolContext($parentRunId, $toolCallId, static fn () => $execution->executeParallel(
            $parentRunId,
            [
                new SubagentTaskDTO(agent: 'batch-a', task: 'Task A'),
                new SubagentTaskDTO(agent: 'batch-b', task: 'Task B'),
            ],
        ));

        $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $outcome);
        $this->assertSame($lifecycleId, $outcome->deferredId);

        /** @var DeferredSubagentBatchRepository $batchRepo */
        $batchRepo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $batch = $batchRepo->findByParentRunAndToolCall($parentRunId, $toolCallId);
        $this->assertNotNull($batch);
        $this->assertSame(ChildRunBatchExecutionModeEnum::Parallel, $batch->executionMode);
        $this->assertSame(2, $batch->totalChildCount);
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Launched, $batch->launchStatus);
        $this->assertCount(2, $batch->children);
        $this->assertSame(1, $batch->children[0]->batchIndex);
        $this->assertSame(2, $batch->children[1]->batchIndex);
        $this->assertSame($childOne['childRunId'], $batch->children[0]->childRunId);
        $this->assertSame($childTwo['childRunId'], $batch->children[1]->childRunId);
        $this->assertSame(DeferredSubagentChildLaunchStatusEnum::Launched, $batch->children[0]->launchStatus);
        $this->assertSame(DeferredSubagentChildLaunchStatusEnum::Launched, $batch->children[1]->launchStatus);

        $this->assertSame([$childOne['childRunId'], $childTwo['childRunId']], $startOrder);
    }

    public function testRetryConvergesOnSameIdentitiesWithoutSecondStartAndRejectsMismatchedIntent(): void
    {
        $parentRunId = 'parent-batch-4a-2';
        $toolCallId = 'call-batch-4a-2';
        $identityFactory = new DeferredSubagentBatchIdentityFactory();
        $lifecycleId = $identityFactory->batchLifecycleId($parentRunId, $toolCallId);

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('start');

        $service = $this->buildBatchLaunchService($agentRunner, [
            $this->parallelDefinition('batch-retry'),
        ]);

        $tasks = [new SubagentTaskDTO(agent: 'batch-retry', task: 'Same task')];
        $first = $this->withToolContext($parentRunId, $toolCallId, static fn () => $service->launch(
            $parentRunId,
            $tasks,
            ChildRunBatchExecutionModeEnum::Parallel,
        ));
        $second = $this->withToolContext($parentRunId, $toolCallId, static fn () => $service->launch(
            $parentRunId,
            $tasks,
            ChildRunBatchExecutionModeEnum::Parallel,
        ));

        $this->assertSame($lifecycleId, $first->deferredId);
        $this->assertSame($lifecycleId, $second->deferredId);

        /** @var DeferredSubagentBatchRepository $batchRepo */
        $batchRepo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $batchAfterRetry = $batchRepo->findByParentRunAndToolCall($parentRunId, $toolCallId);
        $this->assertNotNull($batchAfterRetry);
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Launched, $batchAfterRetry->launchStatus);

        $partialLifecycleId = $identityFactory->batchLifecycleId('parent-batch-4a-partial', 'call-batch-4a-partial');
        $partialChildOne = $identityFactory->childIdentity('parent-batch-4a-partial', 'call-batch-4a-partial', 1);
        $partialChildTwo = $identityFactory->childIdentity('parent-batch-4a-partial', 'call-batch-4a-partial', 2);
        $partialStartedAt = new \DateTimeImmutable('2026-07-13 12:00:00');
        $batchRepo->reserveBatch(
            lifecycleId: $partialLifecycleId,
            parentRunId: 'parent-batch-4a-partial',
            parentTurnNo: 2,
            parentToolCallId: 'call-batch-4a-partial',
            parentOrderIndex: 0,
            executionMode: ChildRunBatchExecutionModeEnum::Parallel,
            totalChildCount: 2,
            deadlineAt: new \DateTimeImmutable('2026-07-13 13:00:00'),
            childIntents: [
                [
                    'batchIndex' => 1,
                    'childRunId' => $partialChildOne['childRunId'],
                    'artifactId' => $partialChildOne['artifactId'],
                    'agentName' => 'batch-retry',
                    'task' => 'Partial one',
                    'launchModel' => 'deepseek/deepseek-v4-flash', 'launchReasoning' => 'medium',
                ],
                [
                    'batchIndex' => 2,
                    'childRunId' => $partialChildTwo['childRunId'],
                    'artifactId' => $partialChildTwo['artifactId'],
                    'agentName' => 'batch-retry',
                    'task' => 'Partial two',
                    'launchModel' => 'deepseek/deepseek-v4-flash', 'launchReasoning' => 'medium',
                ],
            ],
        );

        try {
            $batchRepo->applyLaunchSuccessState(
                'parent-batch-4a-partial',
                'call-batch-4a-partial',
                $partialLifecycleId,
                $partialStartedAt,
                [1],
            );
            $this->fail('Expected RuntimeException for incomplete batch launch persistence');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('left batch Reserved', $e->getMessage());
        }

        $partialBatch = $batchRepo->findByParentRunAndToolCall('parent-batch-4a-partial', 'call-batch-4a-partial');
        $this->assertNotNull($partialBatch);
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Reserved, $partialBatch->launchStatus);
        $this->assertSame(DeferredSubagentChildLaunchStatusEnum::Launched, $partialBatch->children[0]->launchStatus);
        $this->assertSame(DeferredSubagentChildLaunchStatusEnum::Reserved, $partialBatch->children[1]->launchStatus);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('does not match the durable reservation');

        $this->withToolContext($parentRunId, $toolCallId, static fn () => $service->launch(
            $parentRunId,
            [new SubagentTaskDTO(agent: 'batch-retry', task: 'Different task')],
            ChildRunBatchExecutionModeEnum::Parallel,
        ));
    }

    public function testPartialRuntimeStartFailureCancelsStartedChildOnlyAndBlocksDuplicateRetry(): void
    {
        $parentRunId = 'parent-batch-4a-3';
        $toolCallId = 'call-batch-4a-3';
        $identityFactory = new DeferredSubagentBatchIdentityFactory();
        $lifecycleId = $identityFactory->batchLifecycleId($parentRunId, $toolCallId);
        $childOne = $identityFactory->childIdentity($parentRunId, $toolCallId, 1);
        $childTwo = $identityFactory->childIdentity($parentRunId, $toolCallId, 2);
        $childThree = $identityFactory->childIdentity($parentRunId, $toolCallId, 3);

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->exactly(2))->method('start')->willReturnCallback(static function ($input) use ($childTwo, $childOne, $parentRunId, $registry): string {
            if ($input->runId === $childTwo['childRunId']) {
                // Simulate post-start artifact Running persistence degradation (Pending) for child one.
                $registry->update($parentRunId, $childOne['artifactId'], AgentArtifactStatusEnum::Pending);

                throw new \RuntimeException('second child start refused');
            }

            return $input->runId;
        });
        $agentRunner->expects($this->once())->method('cancel')->with($childOne['childRunId'], $this->anything());

        $service = $this->buildBatchLaunchService($agentRunner, [
            $this->parallelDefinition('batch-one'),
            $this->parallelDefinition('batch-two'),
            $this->parallelDefinition('batch-three'),
        ]);

        try {
            $this->withToolContext($parentRunId, $toolCallId, static fn () => $service->launch(
                $parentRunId,
                [
                    new SubagentTaskDTO(agent: 'batch-one', task: 'One'),
                    new SubagentTaskDTO(agent: 'batch-two', task: 'Two'),
                    new SubagentTaskDTO(agent: 'batch-three', task: 'Three'),
                ],
                ChildRunBatchExecutionModeEnum::Parallel,
            ));
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('Subagent batch launch failed', $e->getMessage());
        }

        /** @var DeferredSubagentBatchRepository $batchRepo */
        $batchRepo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $batch = $batchRepo->findByParentRunAndToolCall($parentRunId, $toolCallId);
        $this->assertNotNull($batch);
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Failed, $batch->launchStatus);
        $this->assertSame(DeferredSubagentChildLaunchStatusEnum::Launched, $batch->children[0]->launchStatus);
        $this->assertSame(DeferredSubagentChildLaunchStatusEnum::Failed, $batch->children[1]->launchStatus);
        $this->assertSame(DeferredSubagentChildLaunchStatusEnum::Failed, $batch->children[2]->launchStatus);

        $third = $registry->get($parentRunId, $childThree['artifactId']);
        $this->assertNull($third, 'Never-started child must not leave a Pending artifact reservation after launch abort.');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('previously failed');

        $this->withToolContext($parentRunId, $toolCallId, static fn () => $service->launch(
            $parentRunId,
            [
                new SubagentTaskDTO(agent: 'batch-one', task: 'One'),
                new SubagentTaskDTO(agent: 'batch-two', task: 'Two'),
                new SubagentTaskDTO(agent: 'batch-three', task: 'Three'),
            ],
            ChildRunBatchExecutionModeEnum::Parallel,
        ));
    }

    public function testSingleBatchLaunchUsesForegroundPolicyAndRejectsTwoTasksBeforeReservation(): void
    {
        $parentRunId = 'parent-batch-single-4d1';
        $toolCallId = 'call-batch-single-4d1';
        $otherTool = 'call-batch-single-4d1-other';
        $identityFactory = new DeferredSubagentBatchIdentityFactory();

        $foregroundOnly = new AgentDefinitionDTO(
            name: 'fg-only',
            description: 'Foreground only',
            tools: ['read'],
            instructions: 'Test.',
            parallelAllowed: false,
        );
        $parallelOnly = new AgentDefinitionDTO(
            name: 'par-only',
            description: 'Parallel only',
            tools: ['read'],
            instructions: 'Test.',
            parallelAllowed: true,
        );

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('start')->willReturnCallback(static fn ($input) => $input->runId);

        $batchLaunch = $this->buildBatchLaunchService($agentRunner, [$foregroundOnly, $parallelOnly]);
        $execution = new SubagentExecutionService($batchLaunch);

        $outcome = $this->withToolContext($parentRunId, $toolCallId, static fn () => $execution->execute(
            $parentRunId,
            'fg-only',
            'Single task',
        ));

        $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $outcome);
        $batchRepo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $batch = $batchRepo->findByParentRunAndToolCall($parentRunId, $toolCallId);
        $this->assertNotNull($batch);
        $this->assertSame(ChildRunBatchExecutionModeEnum::Single, $batch->executionMode);
        $this->assertSame(1, $batch->totalChildCount);
        $this->assertCount(1, $batch->children);

        $service = $batchLaunch;
        try {
            $this->withToolContext($parentRunId, $otherTool, static fn () => $service->launch(
                $parentRunId,
                [
                    new SubagentTaskDTO(agent: 'fg-only', task: 'A'),
                    new SubagentTaskDTO(agent: 'fg-only', task: 'B'),
                ],
                ChildRunBatchExecutionModeEnum::Single,
            ));
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('exactly one task', $e->getMessage());
        }

        $this->assertNull($batchRepo->findByParentRunAndToolCall($parentRunId, $otherTool));
    }

    public function testOrdinaryLaunchApiHasNoNullableProfileAndProfiledPathIsRequiredSingleChild(): void
    {
        // Thesis B: ordinary deferred batch launch API has no nullable fork profile;
        // explicit profiled path launches exactly one deferred child and does not accept multi-task misuse.
        $launch = new \ReflectionMethod(DeferredSubagentBatchLaunchService::class, 'launch');
        $this->assertCount(3, $launch->getParameters());
        foreach ($launch->getParameters() as $parameter) {
            $this->assertStringNotContainsStringIgnoringCase('profile', $parameter->getName());
        }

        $profiled = new \ReflectionMethod(DeferredSubagentBatchLaunchService::class, 'launchSingleChildProfile');
        $this->assertTrue($profiled->isPublic());
        $this->assertCount(3, $profiled->getParameters());

        $prepareFromDefinition = new \ReflectionMethod(SubagentLaunchPreparationService::class, 'prepareFromDefinition');
        foreach ($prepareFromDefinition->getParameters() as $parameter) {
            $this->assertStringNotContainsStringIgnoringCase('profile', $parameter->getName());
        }
        $this->assertTrue(
            (new \ReflectionMethod(SubagentLaunchPreparationService::class, 'prepareForkFromProfile'))->isPublic(),
        );
    }

    public function testExecuteParallelHardCapRejectsBeforeReservation(): void
    {
        $parentRunId = 'parent-batch-cap';
        $toolCallId = 'call-batch-cap';
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->never())->method('start');

        $batchLaunch = $this->buildBatchLaunchService($agentRunner, [
            $this->parallelDefinition('cap-a'),
            $this->parallelDefinition('cap-b'),
        ], agentsConfig: new AgentsConfig(maxAgents: 1));
        $execution = new SubagentExecutionService($batchLaunch);

        try {
            $this->withToolContext($parentRunId, $toolCallId, static fn () => $execution->executeParallel(
                $parentRunId,
                [
                    new SubagentTaskDTO(agent: 'cap-a', task: 'A'),
                    new SubagentTaskDTO(agent: 'cap-b', task: 'B'),
                ],
            ));
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('at most 1 agents', $e->getMessage());
        }

        /** @var DeferredSubagentBatchRepository $batchRepo */
        $batchRepo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $this->assertNull($batchRepo->findByParentRunAndToolCall($parentRunId, $toolCallId));
    }

    public function testExecuteParallelPreparationFailureLeavesFailedBatchAndNoStarts(): void
    {
        $parentRunId = 'parent-batch-prep';
        $toolCallId = 'call-batch-prep';
        $runStateRebuilder = $this->createStub(RunStateRebuilderInterface::class);
        $runStateRebuilder->method('rebuildIfStale')->willReturnCallback(static function (RunState $state, string $runId): RunStateReplayResult {
            static $calls = 0;
            ++$calls;
            if ($calls > 1) {
                throw new \RuntimeException('second child context blew up');
            }

            return RunStateReplayResult::rebuilt(new RunState(runId: $runId, status: RunStatus::Running, version: 1, messages: [], model: 'test-model'));
        });

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->never())->method('start');
        $agentRunner->expects($this->never())->method('cancel');

        $def = static fn (string $name) => new AgentDefinitionDTO(
            name: $name,
            description: $name,
            tools: ['read'],
            instructions: 'x',
            parallelAllowed: true,
        );

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $pathResolver = self::getContainer()->get(SessionAgentArtifactPathResolver::class);
        $batchLaunch = $this->buildBatchLaunchService(
            $agentRunner,
            [$def('first-agent'), $def('second-agent'), $def('third-agent')],
            runStateRebuilder: $runStateRebuilder,
        );
        $execution = new SubagentExecutionService($batchLaunch);

        try {
            $this->withToolContext($parentRunId, $toolCallId, static fn () => $execution->executeParallel(
                $parentRunId,
                [
                    new SubagentTaskDTO(agent: 'first-agent', task: 'ok'),
                    new SubagentTaskDTO(agent: 'second-agent', task: 'boom'),
                    new SubagentTaskDTO(agent: 'third-agent', task: 'never'),
                ],
            ));
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('Subagent batch launch failed', $e->getMessage());
            $this->assertStringContainsString('second child context blew up', (string) $e->getPrevious()?->getMessage());
        }

        /** @var DeferredSubagentBatchRepository $batchRepo */
        $batchRepo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $batch = $batchRepo->findByParentRunAndToolCall($parentRunId, $toolCallId);
        $this->assertNotNull($batch);
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Failed, $batch->launchStatus);
        $this->assertCount(3, $batch->children);
        foreach ($batch->children as $child) {
            $this->assertSame(DeferredSubagentChildLaunchStatusEnum::Failed, $child->launchStatus);
        }

        $entries = $registry->list($parentRunId);
        $this->assertCount(2, $entries);
        foreach ($entries as $entry) {
            $this->assertSame(AgentArtifactStatusEnum::Failed, $entry->status);
        }

        $thirdChild = $batch->children[2];
        $this->assertNull($registry->get($parentRunId, $thirdChild->artifactId));
        $this->assertDirectoryDoesNotExist($pathResolver->resolveArtifactDir($parentRunId, $thirdChild->artifactId));
    }

    public function testInsertReservedChildrenRebindsExistingTerminalChildOntoNewBatch(): void
    {
        $childRunId = 'child-resume-rebind-01';
        $oldBatchLifecycleId = 'batch-old-resume-rebind-01';
        $newBatchLifecycleId = 'batch-new-resume-rebind-01';
        $oldArtifactId = 'agent_old_rebind_01';
        $newArtifactId = 'agent_new_rebind_01';

        /** @var SerializerInterface $serializer */
        $serializer = self::getContainer()->get(SerializerInterface::class);
        $completedProjection = new DeferredChildRunLifecycleProjectionDTO(
            childStatus: RunStatus::Completed,
            childTurnNo: 2,
            lastCommittedSeq: 5,
            model: 'old/model',
            reasoning: 'medium',
            latestInputTokens: 12345,
            contextWindow: 200000,
        );
        $projectionArray = $serializer->normalize(
            $completedProjection,
            null,
            [AbstractObjectNormalizer::SKIP_NULL_VALUES => true],
        );
        if (!\is_array($projectionArray)) {
            throw new \RuntimeException('Expected normalized lifecycle projection array.');
        }

        $em = self::getContainer()->get('doctrine')->getManager();
        $seed = new DeferredSubagentChild();
        $seed->batchLifecycleId = $oldBatchLifecycleId;
        $seed->batchIndex = 1;
        $seed->childRunId = $childRunId;
        $seed->artifactId = $oldArtifactId;
        $seed->agentName = 'scout';
        $seed->task = 'old task';
        $seed->launchModel = 'old/model';
        $seed->launchReasoning = 'medium';
        $seed->launchStatus = DeferredSubagentChildLaunchStatusEnum::Launched;
        $seed->childEventCursor = 5;
        $seed->childLifecycleProjection = $projectionArray;
        $seed->terminalCompletedAt = new \DateTimeImmutable('2026-08-21T00:01:00Z');
        $seed->terminalStatus = 'completed';
        $em->persist($seed);
        $em->flush();

        /** @var DeferredSubagentChildRepository $childRepo */
        $childRepo = self::getContainer()->get(DeferredSubagentChildRepository::class);
        $intent = [
            'batchIndex' => 1,
            'childRunId' => $childRunId,
            'artifactId' => $newArtifactId,
            'agentName' => 'scout',
            'task' => 'continue after handoff',
            'launchModel' => 'new/model',
            'launchReasoning' => 'high',
        ];
        $childRepo->insertReservedChildren($newBatchLifecycleId, [$intent]);
        $em->clear();

        $rebound = $childRepo->findEntityByChildRunId($childRunId);
        $this->assertInstanceOf(DeferredSubagentChild::class, $rebound);
        $this->assertSame($newBatchLifecycleId, $rebound->batchLifecycleId);
        $this->assertSame(1, $rebound->batchIndex);
        $this->assertSame($newArtifactId, $rebound->artifactId);
        $this->assertSame('continue after handoff', $rebound->task);
        $this->assertSame('new/model', $rebound->launchModel);
        $this->assertSame('high', $rebound->launchReasoning);
        $this->assertSame(DeferredSubagentChildLaunchStatusEnum::Reserved, $rebound->launchStatus);
        $this->assertSame(5, $rebound->childEventCursor);
        $this->assertNull($rebound->terminalCompletedAt);
        $this->assertNull($rebound->terminalStatus);

        $projection = $childRepo->decodeChildLifecycleProjection($rebound->childLifecycleProjection);
        $this->assertNotNull($projection);
        $this->assertSame(RunStatus::Running, $projection->childStatus);
        $this->assertSame(5, $projection->lastCommittedSeq);
        $this->assertSame(12345, $projection->latestInputTokens);
        $this->assertSame(200000, $projection->contextWindow);
        $this->assertSame('new/model', $projection->model);
        $this->assertSame('high', $projection->reasoning);

        // Second reserve against the same new batch/index converges without another unique conflict.
        $childRepo->insertReservedChildren($newBatchLifecycleId, [$intent]);
        $em->clear();
        $again = $childRepo->findEntityByChildRunId($childRunId);
        $this->assertInstanceOf(DeferredSubagentChild::class, $again);
        $this->assertSame($newBatchLifecycleId, $again->batchLifecycleId);
        $this->assertSame(DeferredSubagentChildLaunchStatusEnum::Reserved, $again->launchStatus);
        $this->assertSame(5, $again->childEventCursor);
    }

    /**
     * @param list<AgentDefinitionDTO> $definitions
     */
    private function buildBatchLaunchService(
        AgentRunnerInterface $agentRunner,
        array $definitions,
        ?TestLogger $logger = null,
        ?AgentsConfig $agentsConfig = null,
        ?RunStateRebuilderInterface $runStateRebuilder = null,
    ): DeferredSubagentBatchLaunchService {
        $logger ??= new TestLogger();
        $artifactLifecycle = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService::class);
        $definitionPolicy = new \Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentLaunchDefinitionPolicyService(
            new AgentDefinitionCatalog($definitions),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\AgentDepthGuard::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\AgentToolPolicyResolver::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader::class),
        );
        $appConfig = self::getContainer()->get(\Ineersa\CodingAgent\Config\AppConfig::class);
        $launchInputFactory = new \Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentChildLaunchInputFactory(
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\AgentPromptBuilder::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Skills\SkillsContextBuilder::class),
            $runStateRebuilder ?? self::getContainer()->get(RunStateRebuilderInterface::class),
            $appConfig,
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\ChildExtensionSelectionService::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Tool\ToolRegistryInterface::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Config\ModelResolver::class),
        );
        $launchPreparation = new SubagentLaunchPreparationService(
            $definitionPolicy,
            $artifactLifecycle,
            $launchInputFactory,
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Fork\ForkChildLaunchInputBuilder::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Fork\ForkToolPolicyResolver::class),
        );
        $lifecyclePolicyFactory = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchLifecyclePolicyDTO::class);
        $batchLaunchService = new \Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchLaunchService(
            $agentRunner,
            $artifactLifecycle,
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\SubagentChildRunBatchLifecycleListener::class),
            $logger,
        );
        $identityFactory = new DeferredSubagentBatchIdentityFactory();
        $runtimeStart = new \Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchRuntimeStartService(
            $agentRunner,
            $artifactLifecycle,
            $batchLaunchService,
            $lifecyclePolicyFactory,
            $logger,
        );
        $batchPreparation = new \Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchPreparationService(
            $launchPreparation,
            $identityFactory,
            $artifactLifecycle,
            $launchInputFactory,
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Fork\ForkChildLaunchInputBuilder::class),
        );

        return new DeferredSubagentBatchLaunchService(
            $batchPreparation,
            self::getContainer()->get(DeferredSubagentBatchRepository::class),
            $runtimeStart,
            self::getContainer()->get(StackToolExecutionContextAccessor::class),
            $agentsConfig ?? self::getContainer()->get(AgentsConfig::class),
            $logger,
        );
    }

    private function parallelDefinition(string $name): AgentDefinitionDTO
    {
        return new AgentDefinitionDTO(
            name: $name,
            description: 'Parallel worker',
            tools: ['read'],
            instructions: 'Test.',
            parallelAllowed: true,
        );
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function withToolContext(string $parentRunId, string $toolCallId, callable $callback): mixed
    {
        $accessor = self::getContainer()->get(StackToolExecutionContextAccessor::class);
        $context = new ToolContext(
            runId: $parentRunId,
            turnNo: 2,
            toolCallId: $toolCallId,
            toolName: 'subagent',
            cancellationToken: new NullCancellationToken(),
            timeoutSeconds: 120,
            orderIndex: 0,
            parentModel: 'test-model',
        );

        return $accessor->with($context, $callback);
    }
}
