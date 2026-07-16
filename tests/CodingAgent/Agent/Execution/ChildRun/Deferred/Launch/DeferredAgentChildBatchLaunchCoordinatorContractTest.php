<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\ChildRun\Deferred\Launch;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\McpAgentModeEnum;
use Ineersa\CodingAgent\Agent\Definition\McpPolicyDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch\DeferredAgentChildBatchLaunchCoordinator;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch\DeferredAgentChildBatchPreparationInterface;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchPreparationService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\SubagentExecutionService;
use Ineersa\CodingAgent\Agent\Execution\SubagentLaunchPreparationService;
use Ineersa\CodingAgent\Agent\Execution\SubagentTaskDTO;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(DeferredAgentChildBatchLaunchCoordinator::class)]
#[Group('db')]
final class DeferredAgentChildBatchLaunchCoordinatorContractTest extends IsolatedKernelTestCase
{
    public function testCoordinatorLaunchesViaPreparationAdapterWithIdempotentRetry(): void
    {
        $parentRunId = 'parent-gf07-coordinator-1';
        $toolCallId = 'call-gf07-coordinator-1';
        $identityFactory = new DeferredSubagentBatchIdentityFactory();
        $lifecycleId = $identityFactory->batchLifecycleId($parentRunId, $toolCallId);

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('start')->willReturnCallback(static fn ($input) => $input->runId);

        $coordinator = $this->buildCoordinator($agentRunner);
        $preparation = $this->buildSubagentPreparation($agentRunner);
        $tasks = [new SubagentTaskDTO(agent: 'gf07-scout', task: 'Contract task')];

        $first = $this->withToolContext($parentRunId, $toolCallId, static fn () => $coordinator->launch(
            $parentRunId,
            $tasks,
            ChildRunBatchExecutionModeEnum::Parallel,
            $preparation,
            1800,
        ));
        $second = $this->withToolContext($parentRunId, $toolCallId, static fn () => $coordinator->launch(
            $parentRunId,
            $tasks,
            ChildRunBatchExecutionModeEnum::Parallel,
            $preparation,
            1800,
        ));

        $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $first);
        $this->assertSame($lifecycleId, $first->deferredId);
        $this->assertSame($lifecycleId, $second->deferredId);

        /** @var DeferredSubagentBatchRepository $batchRepo */
        $batchRepo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $batch = $batchRepo->findByParentRunAndToolCall($parentRunId, $toolCallId);
        $this->assertNotNull($batch);
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Launched, $batch->launchStatus);
        $this->assertCount(1, $batch->children);
        $this->assertSame(DeferredSubagentChildLaunchStatusEnum::Launched, $batch->children[0]->launchStatus);
    }

    public function testSubagentFacadeStillDelegatesThroughCoordinator(): void
    {
        $parentRunId = 'parent-gf07-facade-1';
        $toolCallId = 'call-gf07-facade-1';

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('start')->willReturnCallback(static fn ($input) => $input->runId);

        $batchLaunch = $this->buildSubagentBatchLaunchService($agentRunner);
        $execution = new SubagentExecutionService($batchLaunch);

        $outcome = $this->withToolContext($parentRunId, $toolCallId, static fn () => $execution->execute(
            $parentRunId,
            'gf07-scout',
            'Facade path',
        ));

        $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $outcome);
    }

    private function buildCoordinator(AgentRunnerInterface $agentRunner): DeferredAgentChildBatchLaunchCoordinator
    {
        $logger = new TestLogger();
        $artifactLifecycle = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService::class);
        $lifecyclePolicyFactory = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildRunBatchLifecyclePolicyFactory::class);
        $batchLaunchService = new \Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchLaunchService(
            $agentRunner,
            $artifactLifecycle,
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\SubagentChildRunBatchLifecycleListener::class),
            $logger,
        );
        $runtimeStart = new \Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch\DeferredAgentChildBatchRuntimeStartService(
            $agentRunner,
            $artifactLifecycle,
            $batchLaunchService,
            $lifecyclePolicyFactory,
            $logger,
        );

        return new DeferredAgentChildBatchLaunchCoordinator(
            self::getContainer()->get(DeferredSubagentBatchRepository::class),
            $runtimeStart,
            self::getContainer()->get(StackToolExecutionContextAccessor::class),
            $logger,
        );
    }

    private function buildSubagentPreparation(AgentRunnerInterface $agentRunner): DeferredAgentChildBatchPreparationInterface
    {
        $definition = new AgentDefinitionDTO(
            name: 'gf07-scout',
            description: 'GF-07 contract scout',
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'Test.',
            parallelAllowed: true,
        );
        $definitionPolicy = new \Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentLaunchDefinitionPolicyService(
            new \Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog([$definition]),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\AgentDepthGuard::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\AgentToolPolicyResolver::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader::class),
        );
        $artifactLifecycle = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService::class);
        $launchInputFactory = new \Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentChildLaunchInputFactory(
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\AgentPromptBuilder::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Skills\SkillsContextBuilder::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Context\AgentsContextBuilder::class),
            self::getContainer()->get(\Ineersa\AgentCore\Contract\RunStoreInterface::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Config\AppConfig::class),
        );
        $launchPreparation = new SubagentLaunchPreparationService($definitionPolicy, $artifactLifecycle, $launchInputFactory);

        return new DeferredSubagentBatchPreparationService(
            $launchPreparation,
            new DeferredSubagentBatchIdentityFactory(),
            $artifactLifecycle,
        );
    }

    private function buildSubagentBatchLaunchService(AgentRunnerInterface $agentRunner): DeferredSubagentBatchLaunchService
    {
        return new DeferredSubagentBatchLaunchService(
            $this->buildSubagentPreparation($agentRunner),
            $this->buildCoordinator($agentRunner),
            self::getContainer()->get(AgentsConfig::class),
        );
    }

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
