<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Support;

use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\CodingAgent\Agent\ChildExtensionSelectionService;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Agent\Execution\AgentDepthGuard;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchLifecyclePolicyDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchLaunchService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchPreparationService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchRuntimeStartService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentChildLaunchInputFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentLaunchDefinitionPolicyService;
use Ineersa\CodingAgent\Agent\Execution\SubagentExecutionService;
use Ineersa\CodingAgent\Agent\Execution\SubagentLaunchPreparationService;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;

final class SubagentExecutionServiceFactory
{
    /**
     * @param array<string, mixed> $overrides
     */
    public static function build(array $overrides): SubagentExecutionService
    {
        $defaults = [
            'catalog' => new AgentDefinitionCatalog([]),
            'depthGuard' => new AgentDepthGuard(),
            'policyResolver' => null,
            'promptBuilder' => null,
            'skillsContextBuilder' => null,
            'artifactRegistry' => null,
            'agentRunner' => null,
            'runStateRebuilder' => null,
            'metadataReader' => null,
            'relationshipReader' => null,
            'childRunDirectory' => null,
            'contextAccessor' => null,
            'logger' => null,
            'agentsConfig' => new AgentsConfig(maxAgents: 8),
            'appConfig' => null,
            'modelResolver' => null,
            'batchRepository' => null,
            'lifecycleListener' => null,
            'forkLaunchInputBuilder' => null,
            'forkToolPolicyResolver' => null,
            // Prefer container services for selection validation + owner-tagged tools.
            'childExtensionSelection' => null,
            'toolRegistry' => null,
            'launchInputFactory' => null,
        ];

        $args = array_merge($defaults, $overrides);

        foreach (['policyResolver', 'promptBuilder', 'skillsContextBuilder', 'artifactRegistry', 'agentRunner', 'runStateRebuilder', 'metadataReader', 'relationshipReader', 'childRunDirectory', 'contextAccessor', 'logger', 'appConfig', 'modelResolver', 'batchRepository', 'lifecycleListener', 'forkLaunchInputBuilder', 'forkToolPolicyResolver', 'childExtensionSelection', 'toolRegistry'] as $required) {
            if (null === $args[$required]) {
                throw new \InvalidArgumentException(\sprintf('SubagentExecutionServiceFactory requires override "%s".', $required));
            }
        }

        if (!$args['runStateRebuilder'] instanceof RunStateRebuilderInterface) {
            throw new \InvalidArgumentException('SubagentExecutionServiceFactory requires runStateRebuilder to be a RunStateRebuilderInterface instance.');
        }
        if (!$args['childExtensionSelection'] instanceof ChildExtensionSelectionService) {
            throw new \InvalidArgumentException('SubagentExecutionServiceFactory requires childExtensionSelection to be a ChildExtensionSelectionService instance.');
        }
        if (!$args['toolRegistry'] instanceof ToolRegistryInterface) {
            throw new \InvalidArgumentException('SubagentExecutionServiceFactory requires toolRegistry to be a ToolRegistryInterface instance.');
        }

        $artifactLifecycle = $args['artifactLifecycle'] ?? new ChildRunArtifactLifecycleService($args['artifactRegistry'], $args['childRunDirectory']);

        $definitionPolicy = new SubagentLaunchDefinitionPolicyService($args['catalog'], $args['depthGuard'], $args['policyResolver'], $args['relationshipReader']);
        $launchInputFactory = $args['launchInputFactory'] instanceof SubagentChildLaunchInputFactory
            ? $args['launchInputFactory']
            : new SubagentChildLaunchInputFactory(
                $args['promptBuilder'],
                $args['skillsContextBuilder'],
                $args['runStateRebuilder'],
                $args['appConfig'],
                $args['childExtensionSelection'],
                $args['toolRegistry'],
                $args['metadataReader'],
                $args['modelResolver'],
            );
        $launchPreparation = new SubagentLaunchPreparationService(
            $definitionPolicy,
            $artifactLifecycle,
            $launchInputFactory,
            $args['forkLaunchInputBuilder'],
            $args['forkToolPolicyResolver'],
        );
        $lifecyclePolicy = new ChildRunBatchLifecyclePolicyDTO(
            parentCancelSingleReason: 'Parent run cancelled subagent tool.',
            parentCancelParallelReason: 'Parent run cancelled parallel subagent tool.',
            singleTimeoutCancelReason: 'Subagent timed out.',
            parallelTimeoutCancelReason: 'Parallel subagent timed out.',
            launchAbortSiblingCancelReason: 'Parallel subagent launch aborted after sibling failure.',
        );

        $batchLaunchService = new ChildRunBatchLaunchService(
            $args['agentRunner'],
            $artifactLifecycle,
            $args['lifecycleListener'],
            $args['logger'],
        );

        $identityFactory = new DeferredSubagentBatchIdentityFactory();
        $runtimeStart = new DeferredSubagentBatchRuntimeStartService(
            $args['agentRunner'],
            $artifactLifecycle,
            $batchLaunchService,
            $lifecyclePolicy,
            $args['logger'],
        );
        $batchPreparation = new DeferredSubagentBatchPreparationService(
            $launchPreparation,
            $identityFactory,
            $artifactLifecycle,
            $launchInputFactory,
            $args['forkLaunchInputBuilder'],
        );

        $deferredBatchLaunch = new DeferredSubagentBatchLaunchService(
            $batchPreparation,
            $args['batchRepository'],
            $runtimeStart,
            $args['contextAccessor'],
            $args['agentsConfig'],
            $args['logger'],
        );

        return new SubagentExecutionService($deferredBatchLaunch);
    }
}
