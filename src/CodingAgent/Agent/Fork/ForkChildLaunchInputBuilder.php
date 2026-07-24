<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunMetadata;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;

final class ForkChildLaunchInputBuilder
{
    public function __construct(
        private readonly ForkChildMessageComposer $messageComposer,
        private readonly ForkRuntimeConfigResolver $configResolver,
        private readonly SubagentRunMetadataReader $metadataReader,
        private readonly SkillsContextBuilder $skillsContextBuilder,
        private readonly AppConfig $appConfig,
    ) {
    }

    /**
     * @param array{tools: list<string>, mcp: array<string, mixed>} $policy
     */
    public function buildPrepared(
        ChildRunIdentityDTO $identity,
        ForkLaunchTaskDTO $task,
        array $policy,
        ?string $parentModel = null,
    ): PreparedAgentChildRunDTO {
        $parentRunId = $identity->parentRunId;
        $inherited = $task->inheritedMessages;

        $parentMetadata = $this->metadataReader->readRunStartedMetadata($parentRunId) ?? [];
        $effectiveParentModel = null !== $parentModel && '' !== trim($parentModel)
            ? trim($parentModel)
            : $this->readParentModelFromMetadata($parentMetadata);
        $resolved = $this->configResolver->resolve(
            explicitModel: $task->modelOverride,
            explicitThinking: $task->reasoningOverride,
            parentModel: $effectiveParentModel,
            parentReasoning: $this->readParentReasoningFromMetadata($parentMetadata),
        );

        $composed = $this->messageComposer->compose(
            inheritedMessages: $inherited,
            task: $task->task,
            allowedToolNames: $policy['tools'],
            agentsMd: $this->extractUserContextFromMessages($inherited, 'agents_context'),
            skillsContext: $this->extractSkillsContext($inherited),
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
            model: $resolved->model,
            reasoning: $resolved->thinking,
            toolsScope: [
                'allowed_tools' => $policy['tools'],
                'mcp' => $policy['mcp'],
            ],
            contextWindow: $this->resolveContextWindowForModel($resolved->model),
        );

        return new PreparedAgentChildRunDTO(
            identity: $identity,
            startRunInput: new StartRunInput(
                systemPrompt: $composed['systemPrompt'],
                messages: $composed['messages'],
                runId: $identity->childRunId,
                metadata: $childMetadata,
            ),
        );
    }

    /**
     * @param array<string, mixed> $parentMetadata
     */
    /**
     * @param array<string, mixed> $parentMetadata
     */
    private function readParentModelFromMetadata(array $parentMetadata): ?string
    {
        $model = $parentMetadata['model'] ?? null;

        return \is_string($model) && '' !== trim($model) ? trim($model) : null;
    }

    /**
     * @param array<string, mixed> $parentMetadata
     */
    private function readParentReasoningFromMetadata(array $parentMetadata): ?string
    {
        $reasoning = $parentMetadata['reasoning'] ?? null;

        return \is_string($reasoning) && '' !== trim($reasoning) ? trim($reasoning) : null;
    }

    private function resolveContextWindowForModel(?string $model): ?int
    {
        if (null === $model || '' === trim($model)) {
            return null;
        }
        $catalog = $this->appConfig->catalog;
        if (null === $catalog) {
            return null;
        }
        $ref = AiModelReference::tryParse($model);
        if (null === $ref) {
            return null;
        }
        $definition = $catalog->getModel($ref);
        $window = null !== $definition ? ($definition->contextWindow ?? 0) : 0;

        return $window > 0 ? $window : null;
    }

    /**
     * @param list<AgentMessage> $messages
     */
    private function extractUserContextFromMessages(array $messages, string $source): string
    {
        foreach ($messages as $message) {
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

    /**
     * @param list<AgentMessage> $messages
     */
    private function extractSkillsContext(array $messages): string
    {
        $fromParent = $this->extractUserContextFromMessages($messages, 'skills_context');
        if ('' !== trim($fromParent)) {
            return $fromParent;
        }

        return $this->skillsContextBuilder->build();
    }
}
