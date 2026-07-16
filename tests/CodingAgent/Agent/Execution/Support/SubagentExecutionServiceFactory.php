<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Support;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Agent\Execution\AgentDepthGuard;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch\DeferredAgentChildBatchLaunchCoordinator;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchLaunchService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchPreparationService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchRuntimeStartService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentChildLaunchInputFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentLaunchDefinitionPolicyService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildRunBatchLifecyclePolicyFactory;
use Ineersa\CodingAgent\Agent\Execution\SubagentExecutionService;
use Ineersa\CodingAgent\Agent\Execution\SubagentLaunchPreparationService;
use Ineersa\CodingAgent\Config\AgentsConfig;

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
            'agentsContextBuilder' => null,
            'artifactRegistry' => null,
            'agentRunner' => null,
            'parentRunStore' => null,
            'metadataReader' => null,
            'childRunDirectory' => null,
            'contextAccessor' => null,
            'logger' => null,
            'agentsConfig' => new AgentsConfig(maxAgents: 8),
            'appConfig' => null,
            'batchRepository' => null,
            'lifecycleListener' => null,
        ];

        $args = array_merge($defaults, $overrides);

        foreach (['policyResolver', 'promptBuilder', 'skillsContextBuilder', 'agentsContextBuilder', 'artifactRegistry', 'agentRunner', 'parentRunStore', 'metadataReader', 'childRunDirectory', 'contextAccessor', 'logger', 'appConfig', 'batchRepository', 'lifecycleListener'] as $required) {
            if (null === $args[$required]) {
                throw new \InvalidArgumentException(\sprintf('SubagentExecutionServiceFactory requires override "%s".', $required));
            }
        }

        $artifactLifecycle = $args['artifactLifecycle'] ?? new ChildRunArtifactLifecycleService($args['artifactRegistry'], $args['childRunDirectory']);

        $definitionPolicy = new SubagentLaunchDefinitionPolicyService($args['catalog'], $args['depthGuard'], $args['policyResolver'], $args['metadataReader']);
        $launchInputFactory = new SubagentChildLaunchInputFactory($args['promptBuilder'], $args['skillsContextBuilder'], $args['agentsContextBuilder'], $args['parentRunStore'], $args['appConfig']);
        $launchPreparation = new SubagentLaunchPreparationService($definitionPolicy, $artifactLifecycle, $launchInputFactory);
        $lifecyclePolicyFactory = new SubagentChildRunBatchLifecyclePolicyFactory();

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
            $lifecyclePolicyFactory,
            $args['logger'],
        );
        $batchPreparation = new DeferredSubagentBatchPreparationService(
            $launchPreparation,
            $identityFactory,
            $artifactLifecycle,
        );

        $batchLaunchCoordinator = new DeferredAgentChildBatchLaunchCoordinator(
            $args['batchRepository'],
            $runtimeStart,
            $args['contextAccessor'],
            $args['logger'],
        );

        $deferredBatchLaunch = new DeferredSubagentBatchLaunchService(
            $batchPreparation,
            $batchLaunchCoordinator,
            $args['agentsConfig'],
        );

        return new SubagentExecutionService($deferredBatchLaunch);
    }
}
