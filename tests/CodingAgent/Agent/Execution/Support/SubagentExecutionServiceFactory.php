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
use Ineersa\CodingAgent\Agent\Execution\ChildRun\AgentChildHandoffRenderer;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\AgentChildParentSequenceCoordinator;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\AgentChildProgressEmitter;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ForegroundAgentChildRunSupervisor;
use Ineersa\CodingAgent\Agent\Execution\ParallelSubagentExecutionService;
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

/**
 * Test-only wiring for the refactored subagent execution façade graph.
 */
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

        /** @var AgentToolPolicyResolver $policyResolver */
        $policyResolver = $args['policyResolver'];
        /** @var AgentPromptBuilder $promptBuilder */
        $promptBuilder = $args['promptBuilder'];
        /** @var SkillsContextBuilder $skillsContextBuilder */
        $skillsContextBuilder = $args['skillsContextBuilder'];
        /** @var AgentsContextBuilder $agentsContextBuilder */
        $agentsContextBuilder = $args['agentsContextBuilder'];
        /** @var AgentArtifactRegistry $artifactRegistry */
        $artifactRegistry = $args['artifactRegistry'];
        /** @var AgentRunnerInterface $agentRunner */
        $agentRunner = $args['agentRunner'];
        /** @var RunStoreInterface $runStore */
        $runStore = $args['runStore'];
        /** @var RunStoreInterface $parentRunStore */
        $parentRunStore = $args['parentRunStore'];
        /** @var EventStoreInterface $eventStore */
        $eventStore = $args['eventStore'];
        /** @var CommittedRunEventAppender $committedRunEventAppender */
        $committedRunEventAppender = $args['committedRunEventAppender'];
        /** @var SubagentRunMetadataReader $metadataReader */
        $metadataReader = $args['metadataReader'];
        /** @var AgentChildRunDirectory $childRunDirectory */
        $childRunDirectory = $args['childRunDirectory'];
        /** @var StackToolExecutionContextAccessor $contextAccessor */
        $contextAccessor = $args['contextAccessor'];
        /** @var LoggerInterface $logger */
        $logger = $args['logger'];
        /** @var SubagentChildProgressSummaryBuilder $childProgressSummaryBuilder */
        $childProgressSummaryBuilder = $args['childProgressSummaryBuilder'];
        /** @var AppConfig $appConfig */
        $appConfig = $args['appConfig'];
        /** @var ClockInterface $clock */
        $clock = $args['clock'];

        $sequenceCoordinator = new AgentChildParentSequenceCoordinator(
            $parentRunStore,
            $eventStore,
            $logger,
        );
        $handoffRenderer = new AgentChildHandoffRenderer();
        $artifactFinalizer = new AgentChildArtifactFinalizer(
            $artifactRegistry,
            $handoffRenderer,
            $logger,
        );
        $progressEmitter = new AgentChildProgressEmitter(
            $contextAccessor,
            $committedRunEventAppender,
            $args['progressSnapshotBuilder'],
            $childProgressSummaryBuilder,
            $runStore,
            $clock,
        );
        $launchPreparation = new SubagentLaunchPreparationService(
            $args['catalog'],
            $args['depthGuard'],
            $policyResolver,
            $promptBuilder,
            $skillsContextBuilder,
            $agentsContextBuilder,
            $metadataReader,
            $parentRunStore,
            $appConfig,
        );
        $childRunSupervisor = new ForegroundAgentChildRunSupervisor(
            $artifactRegistry,
            $childRunDirectory,
            $agentRunner,
            $runStore,
            $contextAccessor,
            $progressEmitter,
            $artifactFinalizer,
            $handoffRenderer,
            $sequenceCoordinator,
            $clock,
        );
        $parallelExecution = new ParallelSubagentExecutionService(
            $launchPreparation,
            $artifactRegistry,
            $childRunDirectory,
            $agentRunner,
            $runStore,
            $contextAccessor,
            $progressEmitter,
            $artifactFinalizer,
            $handoffRenderer,
            $sequenceCoordinator,
            $args['agentsConfig'],
            $logger,
            $clock,
        );

        return new SubagentExecutionService(
            $launchPreparation,
            $childRunSupervisor,
            $parallelExecution,
            $args['agentsConfig'],
        );
    }
}
