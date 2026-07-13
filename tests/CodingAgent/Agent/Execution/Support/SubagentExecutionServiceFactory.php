<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Support;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Agent\Execution\AgentDepthGuard;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Infrastructure\ChildRunParentSequenceCoordinator;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchInterruptionService;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchLaunchService;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchProgressService;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchSnapshotTransitionService;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ForegroundChildRunSupervisor;
use Ineersa\CodingAgent\Agent\Execution\ParallelSubagentExecutionService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentChildLaunchInputFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentLaunchDefinitionPolicyService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Progress\SubagentChildRunProgressEmitter;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Result\SubagentChildRunArtifactFinalizer;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Result\SubagentChildRunHandoffRenderer;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\SubagentChildRunBatchLifecycleListener;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildRunBatchLifecyclePolicyFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentParallelAggregateResultFormatter;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentSupervisionResultMapper;
use Ineersa\CodingAgent\Agent\Execution\SubagentExecutionService;
use Ineersa\CodingAgent\Agent\Execution\SubagentLaunchPreparationService;
use Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Symfony\Component\Clock\NativeClock;

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
            'runStore' => null,
            'parentRunStore' => null,
            'eventStore' => null,
            'committedRunEventAppender' => null,
            'metadataReader' => null,
            'childRunDirectory' => null,
            'contextAccessor' => null,
            'logger' => null,
            'agentsConfig' => new AgentsConfig(maxAgents: 8),
            'progressSnapshotBuilder' => new SubagentProgressSnapshotBuilder(),
            'childProgressSummaryBuilder' => null,
            'appConfig' => null,
            'clock' => new NativeClock(),
        ];

        $args = array_merge($defaults, $overrides);

        foreach (['policyResolver', 'promptBuilder', 'skillsContextBuilder', 'agentsContextBuilder', 'artifactRegistry', 'agentRunner', 'runStore', 'parentRunStore', 'eventStore', 'committedRunEventAppender', 'metadataReader', 'childRunDirectory', 'contextAccessor', 'logger', 'childProgressSummaryBuilder', 'appConfig'] as $required) {
            if (null === $args[$required]) {
                throw new \InvalidArgumentException(\sprintf('SubagentExecutionServiceFactory requires override "%s".', $required));
            }
        }

        $sequenceCoordinator = new ChildRunParentSequenceCoordinator($args['parentRunStore'], $args['eventStore'], $args['logger']);
        $handoffRenderer = new SubagentChildRunHandoffRenderer();
        $artifactFinalizer = new SubagentChildRunArtifactFinalizer($args['artifactRegistry'], $handoffRenderer, $args['logger']);
        $lifecycleListener = new SubagentChildRunBatchLifecycleListener(
            new SubagentChildRunProgressEmitter($args['contextAccessor'], $args['committedRunEventAppender'], $args['progressSnapshotBuilder'], $args['childProgressSummaryBuilder'], $args['runStore'], $args['clock']),
            $artifactFinalizer,
            $handoffRenderer,
        );
        $artifactLifecycle = new ChildRunArtifactLifecycleService($args['artifactRegistry'], $args['childRunDirectory']);

        $definitionPolicy = new SubagentLaunchDefinitionPolicyService($args['catalog'], $args['depthGuard'], $args['policyResolver'], $args['metadataReader']);
        $launchInputFactory = new SubagentChildLaunchInputFactory($args['promptBuilder'], $args['skillsContextBuilder'], $args['agentsContextBuilder'], $args['parentRunStore'], $args['appConfig']);
        $launchPreparation = new SubagentLaunchPreparationService($definitionPolicy, $artifactLifecycle, $launchInputFactory);
        $lifecyclePolicyFactory = new SubagentChildRunBatchLifecyclePolicyFactory();

        $launchService = new ChildRunBatchLaunchService($args['agentRunner'], $artifactLifecycle, $lifecycleListener, $args['logger']);
        $transitionService = new ChildRunBatchSnapshotTransitionService($artifactLifecycle, $lifecycleListener);
        $progressService = new ChildRunBatchProgressService($lifecycleListener, $sequenceCoordinator, $args['runStore']);
        $interruptionService = new ChildRunBatchInterruptionService($args['agentRunner'], $args['runStore'], $lifecycleListener, $progressService);
        $batchSupervisor = new ForegroundChildRunSupervisor(
            $lifecycleListener,
            $sequenceCoordinator,
            $args['runStore'],
            $launchService,
            $transitionService,
            $progressService,
            $interruptionService,
            $args['contextAccessor'],
            $args['clock'],
        );
        $parallelFormatter = new SubagentParallelAggregateResultFormatter();
        $resultMapper = new SubagentSupervisionResultMapper($parallelFormatter, $handoffRenderer, $args['agentsConfig']);

        $parallelExecution = new ParallelSubagentExecutionService($launchPreparation, $batchSupervisor, $launchService, $lifecyclePolicyFactory, $resultMapper, $args['agentsConfig']);

        return new SubagentExecutionService($launchPreparation, $batchSupervisor, $parallelExecution, $resultMapper, $lifecyclePolicyFactory, $args['agentsConfig']);
    }
}
