<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\Batch\Deferred\Launch;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\AgentCore\Domain\Tool\ToolLaunchContextDTO;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchChildIntentDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\SubagentExecutionService;
use Ineersa\CodingAgent\Agent\Execution\SubagentLaunchPreparationService;
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
 * Deferred single-child launch, durable retries, and failure recovery.
 */
#[CoversClass(DeferredSubagentBatchLaunchService::class)]
#[CoversClass(SubagentExecutionService::class)]
#[Group('db')]
final class DeferredSubagentBatchLaunchTest extends IsolatedKernelTestCase
{
    public function testRetryConvergesOnSameIdentitiesWithoutSecondStartAndRejectsMismatchedIntent(): void
    {
        $parentRunId = 'parent-batch-4a-2';
        $toolCallId = 'call-batch-4a-2';
        $identityFactory = new DeferredSubagentBatchIdentityFactory();
        $lifecycleId = $identityFactory->batchLifecycleId($parentRunId, $toolCallId);

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('start');

        $service = $this->buildBatchLaunchService($agentRunner, [
            $this->definition('batch-retry'),
        ]);

        $first = $this->withToolContext($parentRunId, $toolCallId, static fn () => $service->launch(
            $parentRunId,
            'batch-retry',
            'Same task',
        ));
        $second = $this->withToolContext($parentRunId, $toolCallId, static fn () => $service->launch(
            $parentRunId,
            'batch-retry',
            'Same task',
        ));

        $this->assertSame($lifecycleId, $first->deferredId);
        $this->assertSame($lifecycleId, $second->deferredId);

        /** @var DeferredSubagentBatchRepository $batchRepo */
        $batchRepo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $batchAfterRetry = $batchRepo->findByParentRunAndToolCall($parentRunId, $toolCallId);
        $this->assertNotNull($batchAfterRetry);
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Launched, $batchAfterRetry->launchStatus);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Deferred invocation reservation does not match its launch intent.');

        $this->withToolContext($parentRunId, $toolCallId, static fn () => $service->launch(
            $parentRunId,
            'batch-retry',
            'Different task',
        ));
    }

    public function testRuntimeStartFailureCancelsItsRunningChildAndBlocksDuplicateRetry(): void
    {
        $parentRunId = 'parent-batch-runtime-failure';
        $toolCallId = 'call-batch-runtime-failure';
        $child = (new DeferredSubagentBatchIdentityFactory())->childIdentity($parentRunId, $toolCallId, 1);
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('start')->willReturnCallback(static function () use ($registry, $parentRunId, $child): never {
            $registry->update($parentRunId, $child['artifactId'], AgentArtifactStatusEnum::Running);
            throw new \RuntimeException('start failed after running transition');
        });
        $agentRunner->expects($this->once())->method('cancel')->with($child['childRunId'], $this->anything());
        $service = $this->buildBatchLaunchService($agentRunner, [$this->definition('batch-one')]);
        try {
            $this->withToolContext($parentRunId, $toolCallId, static fn () => $service->launch($parentRunId, 'batch-one', 'One'));
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('Subagent batch launch failed', $e->getMessage());
        }
        $batch = self::getContainer()->get(DeferredSubagentBatchRepository::class)->findByParentRunAndToolCall($parentRunId, $toolCallId);
        $this->assertNotNull($batch);
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Failed, $batch->launchStatus);
        $this->assertCount(1, $batch->children);
        $this->assertSame(DeferredSubagentChildLaunchStatusEnum::Failed, $batch->children[0]->launchStatus);
        $this->assertSame(AgentArtifactStatusEnum::Failed, $registry->get($parentRunId, $child['artifactId'])?->status);
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('previously failed');
        $this->withToolContext($parentRunId, $toolCallId, static fn () => $service->launch($parentRunId, 'batch-one', 'One'));
    }

    public function testSingleInvocationLaunchesExactlyOneChild(): void
    {
        $parentRunId = 'parent-batch-single-4d1';
        $toolCallId = 'call-batch-single-4d1';
        $identityFactory = new DeferredSubagentBatchIdentityFactory();

        $foregroundOnly = new AgentDefinitionDTO(
            name: 'fg-only',
            description: 'Foreground only',
            tools: ['read'],
            instructions: 'Test.',
        );
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('start')->willReturnCallback(static fn ($input) => $input->runId);

        $batchLaunch = $this->buildBatchLaunchService($agentRunner, [$foregroundOnly]);
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
        $this->assertCount(1, $batch->children);
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

    public function testPreparationFailureLeavesFailedInvocationAndNoStarts(): void
    {
        $parentRunId = 'parent-batch-prep';
        $toolCallId = 'call-batch-prep';
        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->never())->method('start');
        $agentRunner->expects($this->never())->method('cancel');

        $brokenSecond = new AgentDefinitionDTO(
            name: 'second-agent',
            description: 'second-agent',
            tools: ['read'],
            extensions: ['MissingExtension'],
            inheritProjectContext: false,
            instructions: 'x',
        );

        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $pathResolver = self::getContainer()->get(SessionAgentArtifactPathResolver::class);
        $batchLaunch = $this->buildBatchLaunchService(
            $agentRunner,
            [$brokenSecond],
        );
        $execution = new SubagentExecutionService($batchLaunch);

        try {
            $this->withToolContext($parentRunId, $toolCallId, static fn () => $execution->execute(
                $parentRunId,
                'second-agent',
                'boom',
            ));
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('Subagent batch launch failed:', $e->getMessage());
            $this->assertStringContainsString('MissingExtension', $e->getMessage());
        }

        /** @var DeferredSubagentBatchRepository $batchRepo */
        $batchRepo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $batch = $batchRepo->findByParentRunAndToolCall($parentRunId, $toolCallId);
        $this->assertNotNull($batch);
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Failed, $batch->launchStatus);
        $this->assertCount(1, $batch->children);
        foreach ($batch->children as $child) {
            $this->assertSame(DeferredSubagentChildLaunchStatusEnum::Failed, $child->launchStatus);
        }

        $entries = $registry->list($parentRunId);
        $this->assertCount(1, $entries);
        foreach ($entries as $entry) {
            $this->assertSame(AgentArtifactStatusEnum::Failed, $entry->status);
        }
    }

    public function testLaunchSkipsPrepareAndStartWhenInterruptionIntentAlreadyPersisted(): void
    {
        $parentRunId = 'parent-batch-interrupt-before-launch';
        $toolCallId = 'call-batch-interrupt-before-launch';
        $identityFactory = new DeferredSubagentBatchIdentityFactory();
        $lifecycleId = $identityFactory->batchLifecycleId($parentRunId, $toolCallId);
        $child = $identityFactory->childIdentity($parentRunId, $toolCallId, 1);

        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $repo->reserveBatch(
            lifecycleId: $lifecycleId,
            parentRunId: $parentRunId,
            parentTurnNo: 2,
            parentToolCallId: $toolCallId,
            parentOrderIndex: 0,
            deadlineAt: new \DateTimeImmutable('+600 seconds'),
            childIntent: new DeferredSubagentBatchChildIntentDTO(1, $child['childRunId'], $child['artifactId'], 'batch-a', 'Late start', 'test-model', 'medium'),
        );
        $row = $repo->findEntityByLifecycleId($lifecycleId);
        $this->assertNotNull($row);
        $repo->persistInterruptionIntent(
            $lifecycleId,
            \Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum::ParentCancelled,
            new \DateTimeImmutable(),
            $row->projectionVersion,
        );

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->never())->method('start');
        $agentRunner->expects($this->never())->method('cancel');

        $service = $this->buildBatchLaunchService($agentRunner, [$this->definition('batch-a')]);
        $outcome = $this->withToolContext($parentRunId, $toolCallId, static fn () => $service->launch(
            $parentRunId,
            'batch-a',
            'Late start',
        ));

        $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $outcome);
        $this->assertSame($lifecycleId, $outcome->deferredId);

        $batch = $repo->findByLifecycleId($lifecycleId);
        $this->assertNotNull($batch);
        $this->assertSame(DeferredSubagentChildLaunchStatusEnum::Reserved, $batch->children[0]->launchStatus);
        $this->assertSame(
            \Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum::ParentCancelled,
            $batch->interruptionKind,
        );
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Reserved, $batch->launchStatus);
    }

    public function testInsertReservedChildRebindsExistingTerminalChildOntoNewInvocation(): void
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
        $intent = new DeferredSubagentBatchChildIntentDTO(1, $childRunId, $newArtifactId, 'scout', 'continue after handoff', 'new/model', 'high');
        $childRepo->insertReservedChild($newBatchLifecycleId, $intent);
        $em->clear();

        $rebound = $childRepo->findByChildRunId($childRunId);
        $this->assertNotNull($rebound);
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

        $projection = $rebound->childLifecycleProjection;
        $this->assertNotNull($projection);
        $this->assertSame(RunStatus::Running, $projection->childStatus);
        $this->assertSame(5, $projection->lastCommittedSeq);
        $this->assertSame(12345, $projection->latestInputTokens);
        $this->assertSame(200000, $projection->contextWindow);
        $this->assertSame('new/model', $projection->model);
        $this->assertSame('high', $projection->reasoning);

        // Second reserve against the same new batch/index converges without another unique conflict.
        $childRepo->insertReservedChild($newBatchLifecycleId, $intent);
        $em->clear();
        $again = $childRepo->findByChildRunId($childRunId);
        $this->assertNotNull($again);
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
    ): DeferredSubagentBatchLaunchService {
        $logger ??= new TestLogger();
        $artifactLifecycle = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService::class);
        $definitionPolicy = new \Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentLaunchDefinitionPolicyService(
            new AgentDefinitionCatalog($definitions),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\AgentDepthGuard::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\AgentToolPolicyResolver::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Repository\RunRelationshipReader::class),
        );
        $appConfig = self::getContainer()->get(\Ineersa\CodingAgent\Config\AppConfig::class);
        $launchInputFactory = new \Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentChildLaunchInputFactory(
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\AgentPromptBuilder::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Skills\SkillsContextBuilder::class),
            $appConfig,
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\ChildExtensionSelectionService::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Tool\ToolRegistryInterface::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\RunStartedMetadataReader::class),
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

    private function definition(string $name): AgentDefinitionDTO
    {
        return new AgentDefinitionDTO(
            name: $name,
            description: 'Parallel worker',
            tools: ['read'],
            instructions: 'Test.',
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
        self::getContainer()->get(\Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository::class)->replace(
            new RunState($parentRunId, RunStatus::Running),
        );
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
            launchContext: new ToolLaunchContextDTO(
                kind: ToolLaunchContextDTO::KIND_SUBAGENT,
                producingRunId: $parentRunId,
                producingTurnNo: 2,
                producingModel: 'test-model',
                agentsContext: 'AGENTS',
            ),
        );

        return $accessor->with($context, $callback);
    }
}
