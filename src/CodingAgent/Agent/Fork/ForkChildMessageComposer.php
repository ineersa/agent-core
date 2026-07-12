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
    private const array FRESH_CONTEXT_SOURCES = [
        'agents_context',
        'skills_context',
        'agents_definitions_context',
    ];

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
        $systemMessageText = $this->buildSystemMessageText($snapshot, $allowedToolNames);
        $messages = $this->buildMessages(
            snapshot: $snapshot,
            artifactId: $artifactId,
            systemMessageText: $systemMessageText,
            agentsMd: $agentsMd,
            skillsContext: $skillsContext,
            agentsContext: $agentsContext,
        );

        return [
            'systemPrompt' => '',
            'messages' => $messages,
        ];
    }

    /**
     * @param list<string> $allowedToolNames
     */
    private function buildSystemMessageText(
        ForkSessionSnapshotDTO $snapshot,
        array $allowedToolNames,
    ): string {
        $parts = [];

        $base = $this->systemPromptBuilder->buildChildHarnessFragment($allowedToolNames);
        if ('' !== trim($base)) {
            $parts[] = $base;
        }

        if ('' !== trim($snapshot->forkSystemPromptAppend)) {
            $parts[] = trim($snapshot->forkSystemPromptAppend);
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
        string $systemMessageText,
        string $agentsMd,
        string $skillsContext,
        string $agentsContext,
    ): array {
        $messages = [];

        if ('' !== trim($systemMessageText)) {
            $messages[] = new AgentMessage(
                role: 'system',
                content: [[
                    'type' => 'text',
                    'text' => $systemMessageText,
                ]],
            );
        }

        if ('' !== trim($agentsMd)) {
            $messages[] = new AgentMessage(
                role: 'user-context',
                content: [[
                    'type' => 'text',
                    'text' => trim($agentsMd),
                ]],
                metadata: ['source' => 'agents_context'],
            );
        }

        if ('' !== trim($skillsContext)) {
            $messages[] = new AgentMessage(
                role: 'user-context',
                content: [[
                    'type' => 'text',
                    'text' => trim($skillsContext),
                ]],
                metadata: ['source' => 'skills_context'],
            );
        }

        if ('' !== trim($agentsContext)) {
            $messages[] = new AgentMessage(
                role: 'user-context',
                content: [[
                    'type' => 'text',
                    'text' => trim($agentsContext),
                ]],
                metadata: ['source' => 'agents_definitions_context'],
            );
        }

        foreach ($this->inheritedSnapshotMessages($snapshot->messages) as $message) {
            $messages[] = $message;
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
     * Inherited compacted history without parent prologue or stale context channels.
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

        $body = 0 === $skip
            ? array_values(array_filter(
                $snapshotMessages,
                static fn (AgentMessage $message): bool => 'system' !== $message->role,
            ))
            : \array_slice($snapshotMessages, $skip);

        $filtered = [];
        foreach ($body as $message) {
            if ('user-context' === $message->role) {
                $source = $message->metadata['source'] ?? null;
                if (\is_string($source) && \in_array($source, self::FRESH_CONTEXT_SOURCES, true)) {
                    continue;
                }
            }

            $filtered[] = $message;
        }

        return $filtered;
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
