<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\Batch\Deferred\Launch;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\McpAgentModeEnum;
use Ineersa\CodingAgent\Agent\Definition\McpPolicyDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Preparation\DeferredSubagentChildPreparationStrategyInterface;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchService;
use Ineersa\CodingAgent\Agent\Execution\SubagentExecutionService;
use Ineersa\CodingAgent\Agent\Execution\SubagentLaunchPreparationService;
use Ineersa\CodingAgent\Agent\Execution\SubagentTaskDTO;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('db')]
final class DeferredSubagentBatchCustomPreparationSeamTest extends IsolatedKernelTestCase
{
    public function testCustomPreparationStrategyIsUsedWhileDefaultExecutePathUnchanged(): void
    {
        $parentRunId = 'parent-custom-seam-1';
        $toolCallId = 'call-custom-seam-1';
        $customMarker = 'custom-prepared-marker';

        $strategy = new class ($customMarker) implements DeferredSubagentChildPreparationStrategyInterface {
            public function __construct(private readonly string $marker)
            {
            }

            public function prepare(
                string $parentRunId,
                ChildRunIdentityDTO $identity,
                AgentDefinitionDTO $definition,
                string $agentName,
                string $task,
            ): PreparedAgentChildRunDTO {
                return new PreparedAgentChildRunDTO(
                    identity: $identity,
                    startRunInput: new StartRunInput(
                        systemPrompt: $this->marker,
                        messages: [],
                        runId: $identity->childRunId,
                    ),
                );
            }
        };

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('start')->willReturnCallback(
            static function (StartRunInput $input): string {
                return $input->runId;
            },
        );

        $batchLaunch = $this->buildBatchLaunchService($agentRunner, [$this->scoutDefinition()]);
        $outcome = $this->withToolContext($parentRunId, $toolCallId, static fn () => $batchLaunch->launch(
            $parentRunId,
            [new SubagentTaskDTO(agent: 'scout', task: 'Custom seam task')],
            ChildRunBatchExecutionModeEnum::Single,
            $strategy,
        ));

        $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $outcome);

        $startedSystemPrompt = null;
        $defaultRunner = $this->createMock(AgentRunnerInterface::class);
        $defaultRunner->expects($this->once())->method('start')->willReturnCallback(
            static function (StartRunInput $input) use (&$startedSystemPrompt): string {
                $startedSystemPrompt = $input->systemPrompt;

                return $input->runId;
            },
        );

        $defaultBatchLaunch = $this->buildBatchLaunchService($defaultRunner, [$this->scoutDefinition()]);
        $execution = new SubagentExecutionService($defaultBatchLaunch);
        $this->withToolContext('parent-default-seam-1', 'call-default-seam-1', static fn () => $execution->execute(
            'parent-default-seam-1',
            'scout',
            'Default subagent task',
        ));

        $this->assertNotSame($customMarker, $startedSystemPrompt);
        $this->assertIsString($startedSystemPrompt);
        $this->assertNotSame('', trim((string) $startedSystemPrompt));
    }

    /**
     * @param list<AgentDefinitionDTO> $definitions
     */
    private function buildBatchLaunchService(AgentRunnerInterface $agentRunner, array $definitions): DeferredSubagentBatchLaunchService
    {
        $logger = new TestLogger();
        $artifactLifecycle = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService::class);
        $definitionPolicy = new \Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentLaunchDefinitionPolicyService(
            new AgentDefinitionCatalog($definitions),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\AgentDepthGuard::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\AgentToolPolicyResolver::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader::class),
        );
        $launchInputFactory = new \Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentChildLaunchInputFactory(
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\AgentPromptBuilder::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Skills\SkillsContextBuilder::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Context\AgentsContextBuilder::class),
            self::getContainer()->get(RunStoreInterface::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Config\AppConfig::class),
        );
        $launchPreparation = new SubagentLaunchPreparationService($definitionPolicy, $artifactLifecycle, $launchInputFactory);
        $lifecyclePolicyFactory = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildRunBatchLifecyclePolicyFactory::class);
        $batchLaunchService = new \Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchLaunchService(
            $agentRunner,
            $artifactLifecycle,
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\SubagentChildRunBatchLifecycleListener::class),
            $logger,
        );
        $identityFactory = new \Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchIdentityFactory();
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
        );

        return new DeferredSubagentBatchLaunchService(
            $batchPreparation,
            self::getContainer()->get(\Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository::class),
            $runtimeStart,
            self::getContainer()->get(StackToolExecutionContextAccessor::class),
            self::getContainer()->get(AgentsConfig::class),
            $logger,
        );
    }

    private function scoutDefinition(): AgentDefinitionDTO
    {
        return new AgentDefinitionDTO(
            name: 'scout',
            description: 'scout',
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'Scout agent.',
            foregroundAllowed: true,
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
