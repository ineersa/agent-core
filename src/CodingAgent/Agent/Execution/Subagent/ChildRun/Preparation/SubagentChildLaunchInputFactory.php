<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation;

use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Domain\Run\RunMetadata;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\CodingAgent\Agent\ChildExtensionSelectionService;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Execution\AgentPromptBuilder;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\AgentChildLaunchContextDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO;
use Ineersa\CodingAgent\Agent\Execution\RunStartedMetadataReader;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;

final class SubagentChildLaunchInputFactory
{
    public function __construct(
        private readonly AgentPromptBuilder $promptBuilder,
        private readonly SkillsContextBuilder $skillsContextBuilder,
        private readonly RunStateRebuilderInterface $runStateRebuilder,
        private readonly AppConfig $appConfig,
        private readonly ChildExtensionSelectionService $childExtensionSelection,
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly RunStartedMetadataReader $metadataReader,
        private readonly ModelResolver $modelResolver,
    ) {
    }

    /**
     * Resolve concrete non-empty launch model/reasoning without building prompts.
     *
     * @return array{model: string, reasoning: string}
     */
    public function resolveLaunchIdentity(
        AgentDefinitionDTO $definition,
        string $parentRunId,
        ?string $parentModel = null,
    ): array {
        return [
            'model' => $this->resolveEffectiveChildModel($definition->model, $parentModel),
            'reasoning' => $this->resolveEffectiveChildReasoning($definition->thinking, $parentRunId),
        ];
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
        ?string $parentModel = null,
    ): PreparedAgentChildRunDTO {
        $effectiveExtensions = $this->childExtensionSelection->resolveForSubagent($definition);
        $this->childExtensionSelection->assertSelectedAvailable(
            $effectiveExtensions,
            \sprintf('Subagent "%s" launch', $identity->displayName),
        );
        $allowedTools = $this->filterToolsByExtensions($allowedTools, $effectiveExtensions);

        $launchContext = $this->resolveChildLaunchContext($identity->parentRunId, $definition);
        $prompt = $this->promptBuilder->build(
            definition: $definition,
            task: $identity->taskSummary,
            artifactId: $identity->artifactId,
            allowedTools: $allowedTools,
            agentsMd: $launchContext->agentsMd,
            skillsContext: $launchContext->skillsContext,
            allowedExtensions: $effectiveExtensions,
        );

        // Pin the effective child model at launch from explicit override or
        // the exact parent execution model that produced the tool call.
        $effectiveModel = $this->resolveEffectiveChildModel($definition->model, $parentModel);
        // Reasoning: definition thinking override, else canonical parent/session
        // resolution (run_started metadata → session → ai.default_reasoning → medium).
        $effectiveReasoning = $this->resolveEffectiveChildReasoning(
            $definition->thinking,
            $identity->parentRunId,
        );

        $childMetadata = $this->buildChildRunMetadata(
            parentRunId: $identity->parentRunId,
            agentName: $identity->displayName,
            artifactId: $identity->artifactId,
            model: $effectiveModel,
            reasoning: $effectiveReasoning,
            allowedTools: $allowedTools,
            mcp: $mcp,
            extensions: $effectiveExtensions,
        );

        // Prepared identity always carries the concrete launch identity used for RunMetadata.
        $launchIdentity = new ChildRunIdentityDTO(
            parentRunId: $identity->parentRunId,
            childRunId: $identity->childRunId,
            artifactId: $identity->artifactId,
            displayName: $identity->displayName,
            taskSummary: $identity->taskSummary,
            artifactKind: $identity->artifactKind,
            batchIndex: $identity->batchIndex,
        );

        return new PreparedAgentChildRunDTO(
            identity: $launchIdentity,
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
     * @param list<string>         $extensions
     */
    private function buildChildRunMetadata(
        string $parentRunId,
        string $agentName,
        string $artifactId,
        string $model,
        string $reasoning,
        array $allowedTools,
        array $mcp,
        array $extensions,
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
            extensions: $extensions,
        );
    }

    private function resolveEffectiveChildModel(?string $definitionModel, ?string $parentModel): string
    {
        $explicit = null !== $definitionModel ? trim($definitionModel) : '';
        if ('' !== $explicit) {
            return $explicit;
        }

        $inherited = null !== $parentModel ? trim($parentModel) : '';
        if ('' !== $inherited) {
            return $inherited;
        }

        throw new \RuntimeException('Cannot launch child run: missing explicit child model and parent execution model snapshot.');
    }

    private function resolveEffectiveChildReasoning(?string $definitionThinking, string $parentRunId): string
    {
        $explicit = null !== $definitionThinking ? trim($definitionThinking) : null;
        if (null !== $explicit && '' === $explicit) {
            $explicit = null;
        }

        // Prefer durable parent run_started reasoning when definition has no override,
        // then fall through the canonical ModelResolver session/default/product chain.
        if (null === $explicit) {
            $parentMetadata = $this->metadataReader->readRunStartedMetadata($parentRunId);
            $parentReasoning = $parentMetadata?->reasoning;
            if (null !== $parentReasoning && '' !== trim($parentReasoning)) {
                return trim($parentReasoning);
            }
        }

        $resolved = trim($this->modelResolver->resolveInitialReasoning($explicit, $parentRunId));
        if ('' === $resolved) {
            throw new \RuntimeException('Cannot launch child run: canonical reasoning resolution produced an empty value.');
        }

        return $resolved;
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

    private function resolveChildLaunchContext(string $parentRunId, AgentDefinitionDTO $definition): AgentChildLaunchContextDTO
    {
        $agentsMd = $definition->inheritProjectContext
            ? $this->extractUserContextBySource($parentRunId, 'agents_context')
            : '';

        return new AgentChildLaunchContextDTO(
            agentsMd: $agentsMd,
            skillsContext: $this->resolveSkillsContextForChild($definition),
        );
    }

    private function resolveSkillsContextForChild(AgentDefinitionDTO $definition): string
    {
        if ([] === $definition->skills) {
            return '';
        }

        return $this->skillsContextBuilder->buildFor($definition->skills);
    }

    /**
     * @param list<string> $allowedTools
     * @param list<string> $allowedExtensions
     *
     * @return list<string>
     */
    private function filterToolsByExtensions(array $allowedTools, array $allowedExtensions): array
    {
        $allowed = array_fill_keys($allowedExtensions, true);

        return array_values(array_filter(
            $allowedTools,
            function (string $name) use ($allowed): bool {
                $definition = $this->toolRegistry->toolDefinition($name);
                if (null === $definition) {
                    return true;
                }
                $owner = $definition->extensionOwnerClass;

                return null === $owner || isset($allowed[$owner]);
            },
        ));
    }

    private function extractUserContextBySource(string $parentRunId, string $source): string
    {
        $state = $this->runStateRebuilder
            ->rebuildIfStale(RunState::queued($parentRunId), $parentRunId)
            ->rebuiltState;
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
