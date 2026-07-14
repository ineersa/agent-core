<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\McpAgentModeEnum;
use Ineersa\CodingAgent\Agent\Definition\McpPolicyDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchLaunchService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentChildLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\SubagentLaunchPreparationService;
use Ineersa\CodingAgent\Agent\Execution\SubagentTaskDTO;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Piece 4A: durable normalized deferred batch launch foundation (not production cutover).
 */
#[CoversClass(DeferredSubagentBatchLaunchService::class)]
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

        $service = $this->buildBatchLaunchService($agentRunner, [
            $this->parallelDefinition('batch-a'),
            $this->parallelDefinition('batch-b'),
        ]);

        $outcome = $this->withToolContext($parentRunId, $toolCallId, static fn () => $service->launch(
            $parentRunId,
            [
                new SubagentTaskDTO(agent: 'batch-a', task: 'Task A'),
                new SubagentTaskDTO(agent: 'batch-b', task: 'Task B'),
            ],
            ChildRunBatchExecutionModeEnum::Parallel,
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

    /**
     * @param list<AgentDefinitionDTO> $definitions
     */
    private function buildBatchLaunchService(
        AgentRunnerInterface $agentRunner,
        array $definitions,
        ?TestLogger $logger = null,
    ): DeferredSubagentBatchLaunchService {
        $logger ??= new TestLogger();
        $artifactLifecycle = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService::class);
        $definitionPolicy = new \Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentLaunchDefinitionPolicyService(
            new AgentDefinitionCatalog($definitions),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\AgentDepthGuard::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\AgentToolPolicyResolver::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader::class),
        );
        $launchInputFactory = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentChildLaunchInputFactory::class);
        $launchPreparation = new SubagentLaunchPreparationService($definitionPolicy, $artifactLifecycle, $launchInputFactory);
        $lifecyclePolicyFactory = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildRunBatchLifecyclePolicyFactory::class);
        $batchLaunchService = new \Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchLaunchService(
            $agentRunner,
            $artifactLifecycle,
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\SubagentChildRunBatchLifecycleListener::class),
            $logger,
        );
        $identityFactory = new DeferredSubagentBatchIdentityFactory();
        $runtimeStart = new \Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchRuntimeStartService(
            $agentRunner,
            $artifactLifecycle,
            $batchLaunchService,
            $lifecyclePolicyFactory,
            $logger,
        );
        $batchPreparation = new \Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchPreparationService(
            $launchPreparation,
            $identityFactory,
            $artifactLifecycle,
            $runtimeStart,
        );

        return new DeferredSubagentBatchLaunchService(
            $batchPreparation,
            self::getContainer()->get(DeferredSubagentBatchRepository::class),
            $runtimeStart,
            self::getContainer()->get(StackToolExecutionContextAccessor::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Config\AgentsConfig::class),
            $logger,
        );
    }

    private function parallelDefinition(string $name): AgentDefinitionDTO
    {
        return new AgentDefinitionDTO(
            name: $name,
            description: 'Parallel worker',
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
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
        );

        return $accessor->with($context, $callback);
    }
}
