<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Support;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Agent\Execution\AgentDepthGuard;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\AgentChildArtifactFinalizer;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\AgentChildArtifactLifecycleAdapter;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\AgentChildHandoffRenderer;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\AgentChildParentSequenceCoordinator;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\AgentChildProgressEmitter;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\AgentChildRunProcessAdapter;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunBatchInterruptionCoordinator;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunBatchLaunchAbortService;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunBatchLaunchCoordinator;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunBatchProgressCoordinator;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunBatchSnapshotTransitionCoordinator;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ForegroundAgentChildRunSupervisor;
use Ineersa\CodingAgent\Agent\Execution\ParallelSubagentExecutionService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildLaunchInputFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildRunBatchLifecyclePolicyFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildRunProgressSinkAdapter;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildRunTerminalizerAdapter;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentLaunchDefinitionPolicyService;
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

        $policyResolver = $args['policyResolver'];
        $promptBuilder = $args['promptBuilder'];
        $skillsContextBuilder = $args['skillsContextBuilder'];
        $agentsContextBuilder = $args['agentsContextBuilder'];
        $artifactRegistry = $args['artifactRegistry'];
        $agentRunner = $args['agentRunner'];
        $runStore = $args['runStore'];
        $parentRunStore = $args['parentRunStore'];
        $eventStore = $args['eventStore'];
        $committedRunEventAppender = $args['committedRunEventAppender'];
        $metadataReader = $args['metadataReader'];
        $childRunDirectory = $args['childRunDirectory'];
        $contextAccessor = $args['contextAccessor'];
        $logger = $args['logger'];
        $childProgressSummaryBuilder = $args['childProgressSummaryBuilder'];
        $appConfig = $args['appConfig'];
        $clock = $args['clock'];

        $sequenceCoordinator = new AgentChildParentSequenceCoordinator($parentRunStore, $eventStore, $logger);
        $handoffRenderer = new AgentChildHandoffRenderer();
        $artifactFinalizer = new AgentChildArtifactFinalizer($artifactRegistry, $handoffRenderer, $logger);
        $terminalizer = new SubagentChildRunTerminalizerAdapter($artifactFinalizer, $handoffRenderer);
        $progressEmitter = new AgentChildProgressEmitter($contextAccessor, $committedRunEventAppender, $args['progressSnapshotBuilder'], $childProgressSummaryBuilder, $runStore, $clock);
        $progressSink = new SubagentChildRunProgressSinkAdapter($progressEmitter);
        $processPort = new AgentChildRunProcessAdapter($agentRunner, $runStore);
        $artifactLifecycle = new AgentChildArtifactLifecycleAdapter($artifactRegistry, $childRunDirectory);

        $definitionPolicy = new SubagentLaunchDefinitionPolicyService($args['catalog'], $args['depthGuard'], $policyResolver, $metadataReader);
        $launchInputFactory = new SubagentChildLaunchInputFactory($promptBuilder, $skillsContextBuilder, $agentsContextBuilder, $parentRunStore, $appConfig);
        $launchPreparation = new SubagentLaunchPreparationService($definitionPolicy, $artifactLifecycle, $launchInputFactory);
        $lifecyclePolicyFactory = new SubagentChildRunBatchLifecyclePolicyFactory();

        $launchCoordinator = new ChildRunBatchLaunchCoordinator($processPort, $artifactLifecycle);
        $launchAbortService = new ChildRunBatchLaunchAbortService($artifactLifecycle, $processPort, $terminalizer, $logger);
        $transitionCoordinator = new ChildRunBatchSnapshotTransitionCoordinator($artifactLifecycle, $terminalizer);
        $progressCoordinator = new ChildRunBatchProgressCoordinator($progressSink, $sequenceCoordinator, $processPort);
        $interruptionCoordinator = new ChildRunBatchInterruptionCoordinator($processPort, $terminalizer, $progressCoordinator);
        $batchSupervisor = new ForegroundAgentChildRunSupervisor($processPort, $terminalizer, $sequenceCoordinator, $launchCoordinator, $launchAbortService, $transitionCoordinator, $progressCoordinator, $interruptionCoordinator, $contextAccessor, $clock);
        $parallelFormatter = new SubagentParallelAggregateResultFormatter();
        $resultMapper = new SubagentSupervisionResultMapper($parallelFormatter, $handoffRenderer, $args['agentsConfig']);

        $parallelExecution = new ParallelSubagentExecutionService($launchPreparation, $batchSupervisor, $launchAbortService, $lifecyclePolicyFactory, $resultMapper, $args['agentsConfig']);

        return new SubagentExecutionService($launchPreparation, $batchSupervisor, $parallelExecution, $resultMapper, $lifecyclePolicyFactory, $args['agentsConfig']);
    }
}
