<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\SystemPromptModeEnum;

/**
 * Builds the system prompt and initial messages for a child agent run.
 *
 * The child prompt includes:
 *  1. Agent definition's instructions as the system prompt.
 *  2. AGENTS.md / project context (when inherited) in the system prompt.
 *  3. Preloaded skills from agent frontmatter as user-context (skills_context).
 *  4. Non-interactive contract as user-context.
 *  5. The task text as the user message.
 *
 * Only the foreground (non-interactive) v1 mode is implemented.
 */
final readonly class AgentPromptBuilder
{
    /**
     * Build the child system prompt and messages.
     *
     * The system prompt text is included both in the return value's
     * systemPrompt key (for the RunStarted event payload / audit trail)
     * and as the first message in the messages list (role=system) so it
     * is stored in RunState::$messages and reaches the LLM via
     * LlmPlatformAdapter's resolveContextMessages path.
     *
     * This mirrors the pattern used by InProcessAgentSessionClient for
     * parent runs.
     *
     * @param AgentDefinitionDTO $definition         resolved agent definition
     * @param string             $task               the task text
     * @param string             $artifactId         artifact identifier for context
     * @param list<string>       $allowedTools       allowed tool names
     * @param string             $agentsMd           pre-rendered AGENTS.md / project context
     * @param string             $parentSystemPrompt parent system prompt for append mode
     * @param string             $skillsContext      pre-rendered preloaded skill bodies
     *
     * @return array{systemPrompt: string, messages: list<AgentMessage>}
     */
    public function build(
        AgentDefinitionDTO $definition,
        string $task,
        string $artifactId,
        array $allowedTools,
        string $agentsMd,
        string $parentSystemPrompt,
        string $skillsContext = '',
    ): array {
        $systemPrompt = $this->buildSystemPrompt(
            definition: $definition,
            allowedTools: $allowedTools,
            agentsMd: $agentsMd,
            parentSystemPrompt: $parentSystemPrompt,
        );

        $messages = $this->buildMessages(
            definition: $definition,
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
     * Build the child system prompt.
     *
     * @param list<string> $allowedTools
     */
    private function buildSystemPrompt(
        AgentDefinitionDTO $definition,
        array $allowedTools,
        string $agentsMd,
        string $parentSystemPrompt,
    ): string {
        $parts = [];

        // Agent instructions always come first.
        $instructions = trim($definition->instructions);
        if ('' !== $instructions) {
            $parts[] = $instructions;
        }

        // AGENTS.md project context (when inherited).
        if ('' !== $agentsMd) {
            $parts[] = $agentsMd;
        }

        // Append parent system prompt for append mode.
        if (SystemPromptModeEnum::Append === $definition->systemPromptMode
            && '' !== $parentSystemPrompt) {
            $parts[] = $parentSystemPrompt;
        }

        return implode("\n\n", $parts);
    }

    /**
     * Build the initial child messages (system + user-context + user).
     *
     * The system prompt is the first message (role=system) so it is
     * stored in RunState::$messages and reaches the LLM.  This mirrors
     * InProcessAgentSessionClient's pattern for parent runs.
     *
     * @param list<string> $allowedTools
     *
     * @return list<AgentMessage>
     */
    private function buildMessages(
        AgentDefinitionDTO $definition,
        string $task,
        string $artifactId,
        array $allowedTools,
        string $systemPrompt,
        string $skillsContext,
    ): array {
        $messages = [];

        // System prompt as the first message (LLM-visible).
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

        // Non-interactive contract as user-context.
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

        // The task text as the user message.
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
     * Build the non-interactive contract explaining child constraints.
     *
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
