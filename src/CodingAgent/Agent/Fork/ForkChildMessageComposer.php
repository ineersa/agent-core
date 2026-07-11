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
            systemPrompt: $systemPrompt,
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
     * @return list<AgentMessage>
     */
    private function buildMessages(
        ForkSessionSnapshotDTO $snapshot,
        string $artifactId,
        string $systemPrompt,
        string $skillsContext,
    ): array {
        $messages = [];

        if ('' !== trim($systemPrompt)) {
            $messages[] = new AgentMessage(
                role: 'system',
                content: [[
                    'type' => 'text',
                    'text' => $systemPrompt,
                ]],
            );
        }

        foreach ($this->inheritedSnapshotMessages($snapshot->messages) as $message) {
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

    /**
     * Inherited compacted history without the parent's immutable prologue.
     *
     * Virtual compaction embeds leading parent `system` and `user-context`
     * messages at the front of the snapshot. The fork child receives a fresh
     * child-safe system prompt and fresh skills/contract user-context below;
     * stale parent prologue must not reach the LLM.
     *
     * @param list<AgentMessage> $snapshotMessages
     *
     * @return list<AgentMessage>
     */
    private function inheritedSnapshotMessages(array $snapshotMessages): array
    {
        $skip = 0;
        $total = \count($snapshotMessages);
        for ($i = 0; $i < $total; ++$i) {
            $role = $snapshotMessages[$i]->role;
            if ('system' === $role || 'user-context' === $role) {
                ++$skip;
                continue;
            }

            break;
        }

        if (0 === $skip) {
            $filtered = [];
            foreach ($snapshotMessages as $message) {
                if ('system' === $message->role) {
                    continue;
                }
                $filtered[] = $message;
            }

            return $filtered;
        }

        return \array_slice($snapshotMessages, $skip);
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
