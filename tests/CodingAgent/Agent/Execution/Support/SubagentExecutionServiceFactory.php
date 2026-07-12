<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Support;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Context\AgentsContextBuilder;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Agent\Execution\AgentDepthGuard;
use Ineersa\CodingAgent\Agent\Execution\AgentPromptBuilder;
use Ineersa\CodingAgent\Agent\Execution\AgentToolPolicyResolver;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\AgentChildArtifactFinalizer;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\AgentChildArtifactLifecycleAdapter;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\AgentChildHandoffRenderer;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\AgentChildParentSequenceCoordinator;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\AgentChildProgressEmitter;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\AgentChildRunProcessAdapter;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ForegroundAgentChildRunSupervisor;
use Ineersa\CodingAgent\Agent\Execution\ParallelSubagentExecutionService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentArtifactReservationService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildLaunchInputFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildRunProgressSinkAdapter;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildRunTerminalizerAdapter;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentLaunchDefinitionPolicyService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentParallelAggregateResultFormatter;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentParallelLaunchFailureFinalizer;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentSupervisionResultMapper;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder;
use Ineersa\CodingAgent\Agent\Execution\SubagentExecutionService;
use Ineersa\CodingAgent\Agent\Execution\SubagentLaunchPreparationService;
use Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Session\CommittedRunEventAppender;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
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
        $artifactReservation = new SubagentArtifactReservationService($artifactLifecycle);
        $launchInputFactory = new SubagentChildLaunchInputFactory($promptBuilder, $skillsContextBuilder, $agentsContextBuilder, $parentRunStore, $appConfig);
        $launchPreparation = new SubagentLaunchPreparationService($definitionPolicy, $artifactReservation, $launchInputFactory);

        $batchSupervisor = new ForegroundAgentChildRunSupervisor($processPort, $artifactLifecycle, $progressSink, $terminalizer, $sequenceCoordinator, $contextAccessor, $logger, $clock);
        $parallelFormatter = new SubagentParallelAggregateResultFormatter();
        $resultMapper = new SubagentSupervisionResultMapper($parallelFormatter, $handoffRenderer, $args['agentsConfig']);
        $launchFailureFinalizer = new SubagentParallelLaunchFailureFinalizer($artifactLifecycle, $processPort, $terminalizer, $logger);

        $parallelExecution = new ParallelSubagentExecutionService($launchPreparation, $batchSupervisor, $launchFailureFinalizer, $resultMapper, $args['agentsConfig']);

        return new SubagentExecutionService($launchPreparation, $batchSupervisor, $parallelExecution, $resultMapper, $args['agentsConfig']);
    }
}
