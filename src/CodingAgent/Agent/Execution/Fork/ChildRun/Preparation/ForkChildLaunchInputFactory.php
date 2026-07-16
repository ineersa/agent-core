<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Fork\ChildRun\Preparation;

use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Run\RunMetadata;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\CodingAgent\Agent\Context\AgentsContextBuilder;
use Ineersa\CodingAgent\Agent\Execution\AgentMcpToolsResolver;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO;
use Ineersa\CodingAgent\Agent\Execution\Fork\ForkLaunchTaskDTO;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;
use Ineersa\CodingAgent\Agent\Fork\ForkChildMessageComposer;
use Ineersa\CodingAgent\Agent\Fork\ForkConfigResolver;
use Ineersa\CodingAgent\Agent\Fork\ForkContextBuilder;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;

/**
 * Builds PreparedAgentChildRunDTO for a single fork child (virtual compaction + fork message contract).
 */
final class ForkChildLaunchInputFactory
{
    public function __construct(
        private readonly ForkContextBuilder $forkContextBuilder,
        private readonly ForkChildMessageComposer $messageComposer,
        private readonly ForkConfigResolver $forkConfigResolver,
        private readonly RunStoreInterface $parentRunStore,
        private readonly SubagentRunMetadataReader $metadataReader,
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly AgentMcpToolsResolver $mcpToolsResolver,
        private readonly AgentsContextBuilder $agentsContextBuilder,
        private readonly SkillsContextBuilder $skillsContextBuilder,
        private readonly ModelResolver $modelResolver,
        private readonly AppConfig $appConfig,
    ) {
    }

    public function buildPrepared(ChildRunIdentityDTO $identity, ForkLaunchTaskDTO $task): PreparedAgentChildRunDTO
    {
        $parentRunId = $identity->parentRunId;
        $parentState = $this->parentRunStore->get($parentRunId);
        if (null === $parentState) {
            throw new ToolCallException('Fork tool requires an active parent run with persisted state.', retryable: false);
        }

        $taskText = $task->taskSummary();
        $snapshot = $this->forkContextBuilder->build(
            parentMessages: $parentState->messages,
            task: $taskText,
            parentRunId: $parentRunId,
        );

        $policy = $this->resolveForkToolPolicy($parentRunId);
        $allowedTools = $policy['tools'];

        $agentsMd = $this->extractUserContextBySource($parentRunId, 'agents_context');
        $skillsContext = $this->skillsContextBuilder->build();
        $agentsContext = $this->agentsContextBuilder->build();

        $composed = $this->messageComposer->compose(
            snapshot: $snapshot,
            artifactId: $identity->artifactId,
            allowedToolNames: $allowedTools,
            agentsMd: $agentsMd,
            skillsContext: $skillsContext,
            agentsContext: $agentsContext,
        );

        $forkConfig = $this->forkConfigResolver->resolve();
        $resolvedModel = $this->resolveChildModel(
            explicitModel: $task->modelOverride,
            snapshotModel: $forkConfig->resolvedModel ?? $snapshot->resolvedModel,
            parentRunId: $parentRunId,
        );
        $resolvedReasoning = $this->resolveChildReasoning(
            explicitReasoning: $task->reasoningOverride,
            snapshotThinkingLevel: $forkConfig->resolvedThinkingLevel ?? $snapshot->resolvedThinkingLevel,
            parentRunId: $parentRunId,
        );

        $childMetadata = new RunMetadata(
            session: [
                'kind' => 'agent_child',
                'child_kind' => 'fork',
                'parent_run_id' => $parentRunId,
                'agent_name' => $identity->displayName,
                'artifact_id' => $identity->artifactId,
                'interactive' => true,
            ],
            model: $resolvedModel,
            reasoning: $resolvedReasoning,
            toolsScope: [
                'allowed_tools' => $allowedTools,
                'mcp' => $policy['mcp'],
            ],
            contextWindow: ($cw = $this->resolveContextWindowForModel($resolvedModel)) > 0 ? $cw : null,
        );

        return new PreparedAgentChildRunDTO(
            identity: $identity,
            startRunInput: new StartRunInput(
                systemPrompt: '',
                messages: $composed['messages'],
                runId: $identity->childRunId,
                metadata: $childMetadata,
            ),
        );
    }

    /**
     * @return array{tools: list<string>, mcp: array{mode: string, tools: list<string>}}
     */
    private function resolveForkToolPolicy(string $parentRunId): array
    {
        $tools = $this->toolRegistry->activeToolNames();
        $tools = array_values(array_filter(
            $tools,
            static fn (string $name): bool => 'fork' !== $name,
        ));

        $mcpResolved = $this->mcpToolsResolver->resolve(null, $parentRunId);
        foreach ($mcpResolved['mcp_runtime_tools'] as $mcpTool) {
            if (!\in_array($mcpTool, $tools, true)) {
                $tools[] = $mcpTool;
            }
        }

        return [
            'tools' => $tools,
            'mcp' => $mcpResolved['mcp_policy'],
        ];
    }

    private function resolveChildModel(?string $explicitModel, ?string $snapshotModel, string $parentRunId): ?string
    {
        if (null !== $explicitModel && '' !== trim($explicitModel)) {
            return $explicitModel;
        }

        if (null !== $snapshotModel && '' !== trim($snapshotModel)) {
            return $snapshotModel;
        }

        return $this->resolveSessionModelFallback($parentRunId);
    }

    private function resolveChildReasoning(?string $explicitReasoning, ?string $snapshotThinkingLevel, string $parentRunId): ?string
    {
        if (null !== $explicitReasoning && '' !== trim($explicitReasoning)) {
            return $explicitReasoning;
        }

        if (null !== $snapshotThinkingLevel && '' !== trim($snapshotThinkingLevel)) {
            return $snapshotThinkingLevel;
        }

        return $this->resolveSessionReasoningFallback($parentRunId);
    }

    private function resolveSessionModelFallback(string $parentRunId): ?string
    {
        $current = $this->modelResolver->getCurrentModel($parentRunId)?->toString();
        if (null !== $current && '' !== trim($current)) {
            return $current;
        }

        $metadata = $this->metadataReader->readRunStartedMetadata($parentRunId);
        if (null === $metadata) {
            return null;
        }

        $model = $metadata['model'] ?? null;

        return \is_string($model) && '' !== trim($model) ? $model : null;
    }

    private function resolveSessionReasoningFallback(string $parentRunId): ?string
    {
        $current = $this->modelResolver->getCurrentReasoning($parentRunId);
        if ('' !== $current) {
            return $current;
        }

        $metadata = $this->metadataReader->readRunStartedMetadata($parentRunId);
        if (null === $metadata) {
            return null;
        }

        $reasoning = $metadata['reasoning'] ?? null;

        return \is_string($reasoning) && '' !== trim($reasoning) ? $reasoning : null;
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
