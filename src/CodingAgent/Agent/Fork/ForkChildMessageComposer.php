<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\SystemPrompt\SystemPromptBuilder;

/**
 * Composes fork child StartRunInput system prompt and message list.
 *
 * Fork children use the main SYSTEM.md harness (tools, guidelines, date, cwd),
 * not SUBAGENT_SYSTEM.md. Inherited history comes from the virtual snapshot;
 * the delegated task is the final user handoff message only.
 */
final readonly class ForkChildMessageComposer
{
    public function __construct(
        private SystemPromptBuilder $systemPromptBuilder,
    ) {
    }

    /**
     * @param list<string> $allowedToolNames runtime tool names for the fork child (fork excluded)
     *
     * @return array{systemPrompt: string, messages: list<AgentMessage>}
     */
    public function compose(
        ForkSessionSnapshotDTO $snapshot,
        string $artifactId,
        array $allowedToolNames,
        string $agentsMd,
        string $skillsContext,
        string $agentsContext,
    ): array {
        $systemPrompt = $this->buildSystemPrompt($snapshot, $allowedToolNames, $agentsMd, $agentsContext);
        $messages = $this->buildMessages(
            snapshot: $snapshot,
            artifactId: $artifactId,
            allowedToolNames: $allowedToolNames,
            skillsContext: $skillsContext,
        );

        return [
            'systemPrompt' => $systemPrompt,
            'messages' => $messages,
        ];
    }

    /**
     * @param list<string> $allowedToolNames
     */
    private function buildSystemPrompt(
        ForkSessionSnapshotDTO $snapshot,
        array $allowedToolNames,
        string $agentsMd,
        string $agentsContext,
    ): string {
        $parts = [];

        $base = $this->systemPromptBuilder->buildMainHarnessForAllowedTools($allowedToolNames);
        if ('' !== trim($base)) {
            $parts[] = $base;
        }

        if ('' !== trim($snapshot->forkSystemPromptAppend)) {
            $parts[] = trim($snapshot->forkSystemPromptAppend);
        }

        if ('' !== trim($agentsMd)) {
            $parts[] = trim($agentsMd);
        }

        if ('' !== trim($agentsContext)) {
            $parts[] = trim($agentsContext);
        }

        $appends = $this->systemPromptBuilder->buildChildAppendsFragment($allowedToolNames);
        if ('' !== trim($appends)) {
            $parts[] = trim($appends);
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param list<string> $allowedToolNames
     *
     * @return list<AgentMessage>
     */
    private function buildMessages(
        ForkSessionSnapshotDTO $snapshot,
        string $artifactId,
        array $allowedToolNames,
        string $skillsContext,
    ): array {
        $messages = [];

        foreach ($snapshot->messages as $message) {
            $messages[] = $message;
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
                'text' => $this->buildForkChildContract($artifactId),
            ]],
            metadata: ['source' => 'agent_child_contract'],
        );

        $messages[] = new AgentMessage(
            role: 'user',
            content: [[
                'type' => 'text',
                'text' => $snapshot->forkTaskUserMessage,
            ]],
        );

        return $messages;
    }

    private function buildForkChildContract(string $artifactId): string
    {
        return <<<EOT
You are a delegated child agent working on behalf of the parent session.

Artifact ID: {$artifactId}

## Child agent contract

- Your delegated task is in the last user message.
- You may use subagents, ask_human, and tool approval flows when needed.
- Return a dense handoff report for the parent when finished.
EOT;
    }
}
