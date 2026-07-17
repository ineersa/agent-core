<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunMetadata;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;

final class ForkChildLaunchInputBuilder
{
    public function __construct(
        private readonly RunStoreInterface $parentRunStore,
        private readonly ForkSnapshotSanitizer $snapshotSanitizer,
        private readonly ForkChildMessageComposer $messageComposer,
        private readonly ForkRuntimeConfigResolver $configResolver,
        private readonly SubagentRunMetadataReader $metadataReader,
        private readonly ModelResolver $modelResolver,
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
    ): PreparedAgentChildRunDTO {
        $parentRunId = $identity->parentRunId;

        // Prefer pre-compacted inherited messages supplied by ForkExecutionService
        // (one parent RunStore read + sanitize + compact before launch). Fall back
        // only when callers construct a task without inherited messages (legacy tests).
        if (null !== $task->inheritedMessages) {
            $inherited = $task->inheritedMessages;
            $contextSourceMessages = $inherited;
            // Still need parent metadata/model resolution; one optional get for metadata channels
            // is avoided by extracting agents/skills from the inherited snapshot itself.
            $parentStateMessages = $inherited;
        } else {
            $parentState = $this->parentRunStore->get($parentRunId);
            $inherited = null !== $parentState
                ? $this->snapshotSanitizer->sanitize($parentState->messages)
                : [];
            $parentStateMessages = null !== $parentState ? $parentState->messages : [];
            $contextSourceMessages = $parentStateMessages;
        }

        $parentMetadata = $this->metadataReader->readRunStartedMetadata($parentRunId) ?? [];
        $resolved = $this->configResolver->resolve(
            explicitModel: $task->modelOverride,
            explicitThinking: $task->reasoningOverride,
            parentModel: $this->readParentModel($parentRunId, $parentMetadata),
            parentReasoning: $this->readParentReasoning($parentRunId, $parentMetadata),
        );

        $composed = $this->messageComposer->compose(
            inheritedMessages: $inherited,
            task: $task->task,
            artifactId: $identity->artifactId,
            allowedToolNames: $policy['tools'],
            agentsMd: $this->extractUserContextFromMessages($contextSourceMessages, 'agents_context'),
            skillsContext: $this->extractSkillsContext($contextSourceMessages),
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
    private function readParentModel(string $parentRunId, array $parentMetadata): ?string
    {
        $current = $this->modelResolver->getCurrentModel($parentRunId)?->toString();
        if (null !== $current && '' !== trim($current)) {
            return $current;
        }
        $model = $parentMetadata['model'] ?? null;

        return \is_string($model) && '' !== trim($model) ? $model : null;
    }

    /**
     * @param array<string, mixed> $parentMetadata
     */
    private function readParentReasoning(string $parentRunId, array $parentMetadata): ?string
    {
        $current = $this->modelResolver->getCurrentReasoning($parentRunId);
        if ('' !== $current) {
            return $current;
        }
        $reasoning = $parentMetadata['reasoning'] ?? null;

        return \is_string($reasoning) && '' !== trim($reasoning) ? $reasoning : null;
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
