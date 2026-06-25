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
 *  3. Inherited AGENTS.md / project context when provided.
 *  4. APPEND_SYSTEM.md (+ contributors) when systemPromptMode is append.
 *
 * Messages: system, optional skills_context, agent_child_contract, user task.
 */
final readonly class AgentPromptBuilder
{
    public function __construct(
        private SystemPromptBuilder $systemPromptBuilder,
    ) {
    }

    /**
     * @param list<string> $allowedTools
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
    ): array {
        $systemPrompt = $this->buildSystemPrompt(
            definition: $definition,
            allowedTools: $allowedTools,
            agentsMd: $agentsMd,
        );

        $messages = $this->buildMessages(
            task: $task,
            artifactId: $artifactId,
            allowedTools: $allowedTools,
            systemPrompt: $systemPrompt,
            skillsContext: $skillsContext,
        );

        return [
            'systemPrompt' => $systemPrompt,
            'messages' => $messages,
        ];
    }

    /**
     * @param list<string> $allowedTools
     */
    private function buildSystemPrompt(
        AgentDefinitionDTO $definition,
        array $allowedTools,
        string $agentsMd,
    ): string {
        $parts = [];

        $instructions = trim($definition->instructions);
        if ('' !== $instructions) {
            $parts[] = $instructions;
        }

        $parts[] = $this->systemPromptBuilder->buildChildHarnessFragment($allowedTools);

        if ('' !== $agentsMd) {
            $parts[] = $agentsMd;
        }

        if (SystemPromptModeEnum::Append === $definition->systemPromptMode) {
            $appends = $this->systemPromptBuilder->buildChildAppendsFragment($allowedTools);
            if ('' !== trim($appends)) {
                $parts[] = $appends;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param list<string> $allowedTools
     *
     * @return list<AgentMessage>
     */
    private function buildMessages(
        string $task,
        string $artifactId,
        array $allowedTools,
        string $systemPrompt,
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
                'text' => $this->buildNonInteractiveContract(
                    artifactId: $artifactId,
                    allowedTools: $allowedTools,
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
        );

        return $messages;
    }

    /**
     * @param list<string> $allowedTools
     */
    private function buildNonInteractiveContract(
        string $artifactId,
        array $allowedTools,
    ): string {
        $toolList = [] !== $allowedTools
            ? implode(', ', $allowedTools)
            : '(none)';

        return <<<EOT
You are a foreground child agent running inside a parent agent's tool call.

Artifact ID: {$artifactId}
Allowed tools: {$toolList}

## Non-interactive contract

- You are a non-interactive foreground worker.
- Return a dense, complete handoff. Do NOT ask the human interactively.
- If you lack information or need approval/HITL, STOP and explain exactly what
  information or approval is needed. Do NOT enter an interactive waiting state.
- Do NOT ask questions mid-run. If you cannot continue, return a handoff
  explaining what's missing and why.
- Your handoff will be returned to the parent LLM as the tool result.
- Be concise. Prefer concrete file paths, class names, and code snippets
  over vague descriptions.
EOT;
    }
}
