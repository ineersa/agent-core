<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Support;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Agent\Execution\AgentDepthGuard;
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
use Ineersa\CodingAgent\Agent\Fork\ForkChildLaunchInputBuilder;
use Ineersa\CodingAgent\Agent\Fork\ForkChildMessageComposer;
use Ineersa\CodingAgent\Agent\Fork\ForkRuntimeConfigResolver;
use Ineersa\CodingAgent\Agent\Fork\ForkTaskPromptBuilder;
use Ineersa\CodingAgent\Agent\Fork\ForkToolPolicyResolver;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Config\SessionMetadataStore;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\SystemPrompt\SystemPromptBuilder;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use Symfony\AI\Platform\Message\TemplateRenderer\StringTemplateRenderer;

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
        $forkLaunchInputBuilder = $args['forkLaunchInputBuilder'] ?? self::buildForkLaunchInputBuilder($args);
        $forkToolPolicyResolver = $args['forkToolPolicyResolver'] ?? new ForkToolPolicyResolver($args['policyResolver']);
        $launchPreparation = new SubagentLaunchPreparationService(
            $definitionPolicy,
            $artifactLifecycle,
            $launchInputFactory,
            $forkLaunchInputBuilder,
            $forkToolPolicyResolver,
        );
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

    /**
     * Ordinary-subagent tests never exercise fork launch; provide a real
     * ForkChildLaunchInputBuilder so SubagentLaunchPreparationService can be
     * constructed with the expanded production constructor.
     *
     * @param array<string, mixed> $args
     */
    private static function buildForkLaunchInputBuilder(array $args): ForkChildLaunchInputBuilder
    {
        $appConfig = $args['appConfig'];
        $resolvedProjectDir = realpath(__DIR__.'/../../../../../');
        $projectDir = '' !== $appConfig->cwd
            ? $appConfig->cwd
            : (false !== $resolvedProjectDir ? $resolvedProjectDir : __DIR__);

        $toolRegistry = $args['toolRegistry'] ?? null;
        if (!$toolRegistry instanceof ToolRegistryInterface) {
            $toolRegistry = new ToolRegistry();
        }

        $systemPromptBuilder = $args['systemPromptBuilder'] ?? new SystemPromptBuilder(
            toolRegistry: $toolRegistry,
            pathResolver: new SettingsPathResolver($projectDir),
            templateRenderer: new StringTemplateRenderer(),
            appConfig: $appConfig,
            projectDir: $projectDir,
        );

        // HatfieldSessionStore is final; ordinary-subagent tests never exercise
        // fork model lookup, so a constructor-less instance matches
        // ModelResolverTest's test-local pattern.
        $sessionMetaStore = $args['sessionMetaStore'] ?? new SessionMetadataStore(
            (new \ReflectionClass(HatfieldSessionStore::class))->newInstanceWithoutConstructor(),
        );

        return new ForkChildLaunchInputBuilder(
            new ForkChildMessageComposer($systemPromptBuilder, new ForkTaskPromptBuilder()),
            new ForkRuntimeConfigResolver($appConfig->forks),
            $args['metadataReader'],
            new ModelResolver($appConfig, $sessionMetaStore),
            $args['skillsContextBuilder'],
            $appConfig,
        );
    }
}
