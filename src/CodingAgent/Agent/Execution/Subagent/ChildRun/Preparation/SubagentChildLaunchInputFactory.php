<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation;

use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Run\RunMetadata;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\CodingAgent\Agent\Context\AgentsContextBuilder;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Execution\AgentPromptBuilder;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\AgentChildLaunchContextDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Ineersa\Hatfield\ExtensionApi\Model\AiModelReference;

final class SubagentChildLaunchInputFactory
{
    public function __construct(
        private readonly AgentPromptBuilder $promptBuilder,
        private readonly SkillsContextBuilder $skillsContextBuilder,
        private readonly AgentsContextBuilder $agentsContextBuilder,
        private readonly RunStoreInterface $parentRunStore,
        private readonly AppConfig $appConfig,
    ) {
    }

    /**
     * @param list<string>         $allowedTools
     * @param array<string, mixed> $mcp
     */
    public function buildPrepared(
        ChildRunIdentityDTO $identity,
        AgentDefinitionDTO $definition,
        array $allowedTools,
        array $mcp,
    ): PreparedAgentChildRunDTO {
        $launchContext = $this->resolveChildLaunchContext($identity->parentRunId, $definition, $allowedTools);
        $prompt = $this->promptBuilder->build(
            definition: $definition,
            task: $identity->taskSummary,
            artifactId: $identity->artifactId,
            allowedTools: $allowedTools,
            agentsMd: $launchContext->agentsMd,
            skillsContext: $launchContext->skillsContext,
            agentsDefinitionsContext: $launchContext->agentsDefinitionsContext,
        );

        $childMetadata = $this->buildChildRunMetadata(
            parentRunId: $identity->parentRunId,
            agentName: $identity->displayName,
            artifactId: $identity->artifactId,
            model: $definition->model,
            reasoning: $definition->thinking,
            allowedTools: $allowedTools,
            mcp: $mcp,
        );

        return new PreparedAgentChildRunDTO(
            identity: $identity,
            startRunInput: new StartRunInput(
                systemPrompt: $prompt['systemPrompt'],
                messages: $prompt['messages'],
                runId: $identity->childRunId,
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
