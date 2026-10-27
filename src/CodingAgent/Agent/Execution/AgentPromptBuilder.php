<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\SystemPromptModeEnum;
use Ineersa\CodingAgent\SystemPrompt\SystemPromptBuilder;

/**
 * Builds the system prompt and initial messages for a child agent run.
 *
 * Child system prompt includes:
 *  1. Agent definition instructions.
 *  2. Child-safe harness ({available_tools}, {guidelines}, date, cwd) from
 *     SystemPromptBuilder — not the parent SYSTEM.md (no available_agents).
 *  3. APPEND_SYSTEM.md (+ contributors) when systemPromptMode is append.
 *
 * Inherited project context (parent agents_context / AGENTS.md hierarchy) is injected as user-context, not system text.
 * Messages: system, optional agents_context, optional skills_context,
 * agent_child_contract, user task.
 */
final readonly class AgentPromptBuilder
{
    public function __construct(
        private SystemPromptBuilder $systemPromptBuilder,
    ) {
    }

    /**
     * @param list<string>      $allowedTools
     * @param list<string>|null $allowedExtensions null keeps parent-global contributors
     *
     * @return array{systemPrompt: string, messages: list<AgentMessage>}
     */
    public function build(
        AgentDefinitionDTO $definition,
        string $task,
        string $artifactId,
        array $allowedTools,
        string $agentsMd,
        string $skillsContext = '',
        ?array $allowedExtensions = null,
    ): array {
        $systemPrompt = $this->buildSystemPrompt(
            definition: $definition,
            allowedTools: $allowedTools,
            allowedExtensions: $allowedExtensions,
        );

        $messages = $this->buildMessages(
            task: $task,
            artifactId: $artifactId,
            systemPrompt: $systemPrompt,
            agentsMd: $agentsMd,
            skillsContext: $skillsContext,
        );

        return [
            'systemPrompt' => $systemPrompt,
            'messages' => $messages,
        ];
    }

    /**
     * @param list<string>      $allowedTools
     * @param list<string>|null $allowedExtensions
     */
    private function buildSystemPrompt(
        AgentDefinitionDTO $definition,
        array $allowedTools,
        ?array $allowedExtensions,
    ): string {
        $parts = [];

        $instructions = trim($definition->instructions);
        if ('' !== $instructions) {
            $parts[] = $instructions;
        }

        $parts[] = $this->systemPromptBuilder->buildChildHarnessFragment($allowedTools);

        if (SystemPromptModeEnum::Append === $definition->systemPromptMode) {
            $appends = $this->systemPromptBuilder->buildChildAppendsFragment($allowedTools, $allowedExtensions);
            if ('' !== trim($appends)) {
                $parts[] = $appends;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * @return list<AgentMessage>
     */
    private function buildMessages(
        string $task,
        string $artifactId,
        string $systemPrompt,
        string $agentsMd,
        string $skillsContext,
    ): array {
        $messages = [];

        if ('' !== $systemPrompt) {
            $messages[] = new AgentMessage(
                role: 'system',
                content: [[
                    'type' => 'text',
                    'text' => $systemPrompt,
                ]],
            );
        }

        if ('' !== trim($agentsMd)) {
            $messages[] = new AgentMessage(
                role: 'user-context',
                content: [[
                    'type' => 'text',
                    'text' => $agentsMd,
                ]],
                metadata: ['source' => 'agents_context'],
            );
        }

        if ('' !== trim($skillsContext)) {
            $messages[] = new AgentMessage(
                role: 'user-context',
                content: [[
                    'type' => 'text',
                    'text' => $skillsContext,
                ]],
                metadata: ['source' => 'skills_context'],
            );
        }

        $messages[] = new AgentMessage(
            role: 'user-context',
            content: [[
                'type' => 'text',
                'text' => $this->buildInteractiveContract(
                    artifactId: $artifactId,
                ),
            ]],
            metadata: ['source' => 'agent_child_contract'],
        );

        $messages[] = new AgentMessage(
            role: 'user',
            content: [[
                'type' => 'text',
                'text' => $task,
            ]],
            metadata: ['agent_task' => ['artifact_id' => $artifactId]],
        );

        return $messages;
    }

    private function buildInteractiveContract(
        string $artifactId,
    ): string {
        return <<<EOT
You are a child agent working on a task assigned by a parent agent.

Artifact ID: {$artifactId}

- Stay within the assigned scope.
- When notify_parent is available, use it for blockers, questions, or findings
  the parent needs before you finish.
- Use ask_human only when it is available and human input is necessary to proceed.
  Do not block on optional questions.
- Finish with a concise handoff: results, relevant file paths, validation performed,
  and any remaining blockers.
EOT;
    }
}
