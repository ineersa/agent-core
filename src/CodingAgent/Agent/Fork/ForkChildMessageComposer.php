<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\SystemPrompt\SystemPromptBuilder;

final readonly class ForkChildMessageComposer
{
    public function __construct(
        private SystemPromptBuilder $systemPromptBuilder,
        private ForkTaskPromptBuilder $taskPromptBuilder,
    ) {
    }

    /**
     * @param list<AgentMessage> $inheritedMessages
     * @param list<string>       $allowedToolNames
     *
     * @return array{messages: list<AgentMessage>}
     */
    public function compose(
        array $inheritedMessages,
        string $task,
        string $artifactId,
        array $allowedToolNames,
        string $agentsMd,
        string $skillsContext,
    ): array {
        $systemMessageText = $this->buildSystemMessageText($allowedToolNames);
        $messages = [];
        if ('' !== trim($systemMessageText)) {
            $messages[] = new AgentMessage(role: 'system', content: [['type' => 'text', 'text' => $systemMessageText]]);
        }

        foreach ([
            ['text' => $agentsMd, 'source' => 'agents_context'],
            ['text' => $skillsContext, 'source' => 'skills_context'],
        ] as $channel) {
            $body = trim((string) $channel['text']);
            if ('' === $body) {
                continue;
            }
            $messages[] = new AgentMessage(
                role: 'user-context',
                content: [['type' => 'text', 'text' => $body]],
                metadata: ['source' => $channel['source']],
            );
        }

        foreach ($inheritedMessages as $message) {
            if ('system' === $message->role || 'user-context' === $message->role) {
                continue;
            }
            $messages[] = $message;
        }

        $messages[] = new AgentMessage(
            role: 'user-context',
            content: [['type' => 'text', 'text' => $this->buildForkChildContract($artifactId, $allowedToolNames)]],
            metadata: ['source' => 'agent_child_contract'],
        );

        $messages[] = new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => $this->taskPromptBuilder->buildTaskUserMessage($task)]],
        );

        return ['messages' => $messages];
    }

    /**
     * @param list<string> $allowedToolNames
     */
    private function buildSystemMessageText(array $allowedToolNames): string
    {
        $parts = [];
        $base = $this->systemPromptBuilder->buildChildHarnessFragment($allowedToolNames);
        if ('' !== trim($base)) {
            $parts[] = $base;
        }
        $append = $this->taskPromptBuilder->forkChildSystemPromptAppend();
        if ('' !== trim($append)) {
            $parts[] = trim($append);
        }
        $appends = $this->systemPromptBuilder->buildChildAppendsFragment($allowedToolNames);
        if ('' !== trim($appends)) {
            $parts[] = trim($appends);
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param list<string> $allowedToolNames
     */
    private function buildForkChildContract(string $artifactId, array $allowedToolNames): string
    {
        $toolList = implode(', ', $allowedToolNames);

        return <<<EOT
You are an isolated delegated fork child, not the parent session.

Artifact ID: {$artifactId}

- Solve only the delegated task in the final user message.
- You cannot launch fork or subagent; your active tools are: {$toolList}.
- Return a dense handoff with status, evidence, files/symbols touched, validation, risks, and recommended continuation.
- Do not claim tools or capabilities you do not have.
EOT;
    }
}
