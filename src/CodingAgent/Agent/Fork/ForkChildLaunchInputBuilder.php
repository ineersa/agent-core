<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunMetadata;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\CodingAgent\Agent\ChildExtensionSelectionService;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;

final class ForkChildLaunchInputBuilder
{
    public function __construct(
        private readonly ForkChildMessageComposer $messageComposer,
        private readonly ForkRuntimeConfigResolver $configResolver,
        private readonly SubagentRunMetadataReader $metadataReader,
        private readonly SkillsContextBuilder $skillsContextBuilder,
        private readonly AppConfig $appConfig,
        private readonly ChildExtensionSelectionService $childExtensionSelection,
        private readonly ToolRegistryInterface $toolRegistry,
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

        $parentMetadata = $this->metadataReader->readRunStartedMetadata($parentRunId);
        // Explicit parent model still wins; otherwise inherit from parent RunStarted metadata.
        // Fork historically trimmed model/reasoning here (not at the shared decoder).
        $effectiveParentModel = null !== $parentModel && '' !== trim($parentModel)
            ? trim($parentModel)
            : $this->trimInheritedForkConfigString($parentMetadata?->model);
        $resolved = $this->configResolver->resolve(
            explicitModel: $task->modelOverride,
            explicitThinking: $task->reasoningOverride,
            parentModel: $effectiveParentModel,
            parentReasoning: $this->trimInheritedForkConfigString($parentMetadata?->reasoning),
        );

        $effectiveExtensions = $this->childExtensionSelection->resolveForFork();
        $this->childExtensionSelection->assertSelectedAvailable(
            $effectiveExtensions,
            'Fork child launch',
        );

        $allowedTools = $this->filterToolsByExtensions($policy['tools'], $effectiveExtensions);

        $composed = $this->messageComposer->compose(
            inheritedMessages: $inherited,
            task: $task->task,
            allowedToolNames: $allowedTools,
            agentsMd: $this->extractUserContextFromMessages($inherited, 'agents_context'),
            skillsContext: $this->extractSkillsContext($inherited),
            allowedExtensions: $effectiveExtensions,
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
                'allowed_tools' => $allowedTools,
                'mcp' => $policy['mcp'],
            ],
            contextWindow: $this->resolveContextWindowForModel($resolved->model),
            extensions: $effectiveExtensions,
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
     * Historical fork inheritance: nonblank after trim → return trimmed value.
     * Shared decoder preserves raw strings; fork trims only at this consumer.
     */
    private function trimInheritedForkConfigString(?string $value): ?string
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        return trim($value);
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
