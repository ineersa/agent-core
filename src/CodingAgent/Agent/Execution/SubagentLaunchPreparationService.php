<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Run\RunMetadata;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\CodingAgent\Agent\Context\AgentsContextBuilder;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\AgentChildLaunchContextDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\PreparedAgentChildRunDTO;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * Subagent-specific launch preparation: catalog, depth, tool/MCP policy, prompts, metadata.
 */
final class SubagentLaunchPreparationService
{
    public function __construct(
        private readonly AgentDefinitionCatalog $catalog,
        private readonly AgentDepthGuard $depthGuard,
        private readonly AgentToolPolicyResolver $policyResolver,
        private readonly AgentPromptBuilder $promptBuilder,
        private readonly SkillsContextBuilder $skillsContextBuilder,
        private readonly AgentsContextBuilder $agentsContextBuilder,
        private readonly SubagentRunMetadataReader $metadataReader,
        private readonly RunStoreInterface $parentRunStore,
        private readonly AppConfig $appConfig,
    ) {
    }

    public function assertDepthAllowed(string $parentRunId): void
    {
        $parentIsAgentChild = $this->metadataReader->isAgentChild($parentRunId);
        $blockReason = $this->depthGuard->checkLaunchAllowed($parentIsAgentChild);
        if (null !== $blockReason) {
            throw new ToolCallException($blockReason, retryable: false);
        }
    }

    public function requireParallelDefinition(string $agentName): AgentDefinitionDTO
    {
        $definition = $this->requireForegroundDefinition($agentName);
        if (!$definition->parallelAllowed) {
            throw new ToolCallException(\sprintf('Agent "%s" does not allow parallel execution. Set parallelAllowed: true in the agent definition or use single subagent mode.', $agentName), retryable: false);
        }

        return $definition;
    }

    public function requireForegroundDefinition(string $agentName): AgentDefinitionDTO
    {
        try {
            $definition = $this->catalog->requireEnabled($agentName);
        } catch (\RuntimeException $e) {
            throw new ToolCallException(\sprintf('Agent "%s" is not available: %s', $agentName, $e->getMessage()), retryable: false);
        }

        if (!$definition->foregroundAllowed) {
            throw new ToolCallException(\sprintf('Agent "%s" does not allow foreground execution.', $agentName), retryable: false);
        }

        return $definition;
    }

    public function prepareSingle(
        string $parentRunId,
        string $agentName,
        string $task,
    ): PreparedAgentChildRunDTO {
        $definition = $this->requireForegroundDefinition($agentName);
        $this->assertDepthAllowed($parentRunId);

        return $this->prepareFromDefinition($parentRunId, $definition, $agentName, $task);
    }

    public function prepareFromDefinition(
        string $parentRunId,
        AgentDefinitionDTO $definition,
        string $agentName,
        string $task,
        ?string $artifactId = null,
        ?string $childRunId = null,
    ): PreparedAgentChildRunDTO {
        $allowSubagentLaunch = $this->childMayLaunchSubagents($definition);
        $policy = $this->policyResolver->resolve($definition, $parentRunId, $allowSubagentLaunch);
        $allowedTools = $policy['tools'];

        $artifactId ??= 'agent_'.bin2hex(random_bytes(8));
        $childRunId ??= Uuid::v4()->toRfc4122();

        $launchContext = $this->resolveChildLaunchContext($parentRunId, $definition, $allowedTools);

        $prompt = $this->promptBuilder->build(
            definition: $definition,
            task: $task,
            artifactId: $artifactId,
            allowedTools: $allowedTools,
            agentsMd: $launchContext->agentsMd,
            skillsContext: $launchContext->skillsContext,
            agentsDefinitionsContext: $launchContext->agentsDefinitionsContext,
        );

        $childMetadata = $this->buildChildRunMetadata(
            parentRunId: $parentRunId,
            agentName: $agentName,
            artifactId: $artifactId,
            model: $definition->model,
            reasoning: $definition->thinking,
            allowedTools: $allowedTools,
            mcp: $policy['mcp'],
        );

        return new PreparedAgentChildRunDTO(
            parentRunId: $parentRunId,
            childRunId: $childRunId,
            artifactId: $artifactId,
            displayName: $agentName,
            taskSummary: $task,
            definitionModel: $definition->model,
            startRunInput: new StartRunInput(
                systemPrompt: $prompt['systemPrompt'],
                messages: $prompt['messages'],
                runId: $childRunId,
                metadata: $childMetadata,
            ),
        );
    }

    /**
     * @param list<string>         $allowedTools
     * @param array<string, mixed> $mcp
     */
    private function buildChildRunMetadata(
        string $parentRunId,
        string $agentName,
        string $artifactId,
        ?string $model,
        ?string $reasoning,
        array $allowedTools,
        array $mcp,
    ): RunMetadata {
        $contextWindow = $this->resolveContextWindowForModel($model);

        return new RunMetadata(
            session: [
                'kind' => 'agent_child',
                'parent_run_id' => $parentRunId,
                'agent_name' => $agentName,
                'artifact_id' => $artifactId,
                'interactive' => true,
            ],
            model: $model,
            reasoning: $reasoning,
            toolsScope: [
                'allowed_tools' => $allowedTools,
                'mcp' => $mcp,
            ],
            contextWindow: $contextWindow > 0 ? $contextWindow : null,
        );
    }

    private function resolveContextWindowForModel(?string $model): int
    {
        if (null === $model || '' === trim($model)) {
            return 0;
        }

        $catalog = $this->appConfig->catalog;
        if (null === $catalog) {
            return 0;
        }

        $ref = AiModelReference::tryParse($model);
        if (null === $ref) {
            return 0;
        }

        $definition = $catalog->getModel($ref);

        return null !== $definition ? ($definition->contextWindow ?? 0) : 0;
    }

    /**
     * @param list<string> $allowedTools
     */
    private function resolveChildLaunchContext(string $parentRunId, AgentDefinitionDTO $definition, array $allowedTools): AgentChildLaunchContextDTO
    {
        $inheritProject = $definition->inheritProjectContext;
        $inheritAgents = $definition->inheritAgentsMd;
        $agentsMd = ($inheritProject || $inheritAgents)
            ? $this->extractUserContextBySource($parentRunId, 'agents_context')
            : '';

        $skillsContext = $this->resolveSkillsContextForChild($definition);

        $agentsDefinitionsContext = '';
        if (\in_array('subagent', $allowedTools, true)) {
            $agentsDefinitionsContext = $this->extractUserContextBySource($parentRunId, 'agents_definitions_context');
            if ('' === trim($agentsDefinitionsContext)) {
                $agentsDefinitionsContext = $this->agentsContextBuilder->build();
            }
        }

        return new AgentChildLaunchContextDTO(
            agentsMd: $agentsMd,
            skillsContext: $skillsContext,
            agentsDefinitionsContext: $agentsDefinitionsContext,
        );
    }

    private function childMayLaunchSubagents(AgentDefinitionDTO $definition): bool
    {
        $tools = $definition->tools;
        if (null === $tools) {
            return false;
        }

        return \in_array('subagent', $tools, true);
    }

    private function resolveSkillsContextForChild(AgentDefinitionDTO $definition): string
    {
        if ([] === $definition->skills) {
            return '';
        }

        return $this->skillsContextBuilder->buildFor($definition->skills);
    }

    private function extractUserContextBySource(string $parentRunId, string $source): string
    {
        $state = $this->parentRunStore->get($parentRunId);
        if (null === $state) {
            return '';
        }

        foreach ($state->messages as $message) {
            if ('user-context' !== $message->role) {
                continue;
            }
            if ($source !== ($message->metadata['source'] ?? null)) {
                continue;
            }
            foreach ($message->content as $block) {
                if ('text' === ($block['type'] ?? '') && isset($block['text'])) {
                    return (string) $block['text'];
                }
            }
        }

        return '';
    }
}
