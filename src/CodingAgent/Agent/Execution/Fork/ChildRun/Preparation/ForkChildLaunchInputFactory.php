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
use Ineersa\CodingAgent\Agent\Fork\ForkContextBuilder;
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
        private readonly RunStoreInterface $parentRunStore,
        private readonly SubagentRunMetadataReader $metadataReader,
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly AgentMcpToolsResolver $mcpToolsResolver,
        private readonly AgentsContextBuilder $agentsContextBuilder,
        private readonly SkillsContextBuilder $skillsContextBuilder,
        private readonly ModelResolver $modelResolver,
    ) {
    }


    public function buildPreparedFromForkLocal(ChildRunIdentityDTO $identity, ForkLaunchTaskDTO $task, string $forkLocalRunId): PreparedAgentChildRunDTO
    {
        $forkLocalState = $this->parentRunStore->get($forkLocalRunId);
        if (null === $forkLocalState) {
            throw new ToolCallException('Fork pre-launch copy is missing persisted state.', retryable: false);
        }

        $parentRunId = $identity->parentRunId;
        $policy = $this->resolveForkToolPolicy($parentRunId);
        $composed = $this->messageComposer->composeFromMessages(
            inheritedMessages: $forkLocalState->messages,
            task: $task->taskSummary(),
            artifactId: $identity->artifactId,
            allowedToolNames: $policy['tools'],
            agentsMd: $this->extractUserContextBySource($parentRunId, 'agents_context'),
            skillsContext: $this->skillsContextBuilder->build(),
            agentsContext: $this->agentsContextBuilder->build(),
        );

        $resolvedModel = $this->resolveChildModel($task->modelOverride, null, $parentRunId);
        $resolvedReasoning = $this->resolveChildReasoning($task->reasoningOverride, null, $parentRunId);

        return new PreparedAgentChildRunDTO(
            identity: $identity,
            startRunInput: new StartRunInput(
                systemPrompt: '',
                messages: $composed['messages'],
                runId: $identity->childRunId,
                metadata: new RunMetadata(
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
                        'allowed_tools' => $policy['tools'],
                        'mcp' => $policy['mcp'],
                    ],
                ),
            ),
        );
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

        $resolvedModel = $this->resolveChildModel(
            explicitModel: $task->modelOverride,
            snapshotModel: $snapshot->resolvedModel,
            parentRunId: $parentRunId,
        );
        $resolvedReasoning = $this->resolveChildReasoning(
            explicitReasoning: $task->reasoningOverride,
            snapshotThinkingLevel: $snapshot->resolvedThinkingLevel,
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
