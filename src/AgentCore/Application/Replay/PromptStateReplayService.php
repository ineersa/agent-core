<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Replay;

use Ineersa\AgentCore\Domain\Event\RunEvent;

final readonly class PromptStateReplayService
{
    /**
     * @param list<RunEvent> $events
     *
     * @return list<array<string, mixed>>
     */
    public function replayMessages(array $events): array
    {
        $messages = [];

        foreach ($events as $event) {
            $payload = $event->payload;

            if (isset($payload['messages']) && \is_array($payload['messages'])) {
                $messages = [];

                foreach ($payload['messages'] as $message) {
                    if (!\is_array($message)) {
                        continue;
                    }

                    $messages[] = $message;
                }
            }

            if (isset($payload['message']) && \is_array($payload['message'])) {
                $messages[] = $payload['message'];
            }

            // Canonical llm_step_completed events emit the full normalized
            // assistant message structure under payload.assistant_message.
            // Convert the canonical shape into the internal message format:
            //   - Keep role and content as-is
            //   - Move tool_calls from top-level to metadata.tool_calls
            //   - Keep details as-is
            //   - Handle null content (tool-call-only) → empty array
            if (isset($payload['assistant_message']) && \is_array($payload['assistant_message'])) {
                $am = $payload['assistant_message'];

                if (!isset($am['role']) || !\is_string($am['role'])) {
                    // Intentionally skip malformed assistant_message entries
                    // (missing or non-string role) as corrupt/partial replay
                    // input rather than throwing during hot-prompt rebuild.
                    continue;
                }

                $content = \is_array($am['content'] ?? null) ? $am['content'] : [];

                $message = [
                    'role' => $am['role'],
                    'content' => $content,
                ];

                if (isset($am['tool_calls']) && \is_array($am['tool_calls']) && [] !== $am['tool_calls']) {
                    $message['metadata']['tool_calls'] = $am['tool_calls'];
                }

                if (isset($am['details']) && \is_array($am['details']) && [] !== $am['details']) {
                    $message['details'] = $am['details'];
                }

                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    public function estimateTokens(array $messages): int
    {
        $encoded = json_encode($messages);

        if (false === $encoded) {
            return 0;
        }

        return (int) ceil(\strlen($encoded) / 4);
    }
}
