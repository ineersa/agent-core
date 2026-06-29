<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * Sanitizes a fork snapshot by trimming the parent-side launch messages.
 *
 * Algorithm (ported from Pi's sanitizeForkSnapshotBranch in fork.ts):
 *   Scan from the END backward for the last assistant message whose
 *   metadata['tool_calls'] contains an entry with name === 'fork'.
 *   If found, walk backward from that index to find the preceding
 *   user message (role === 'user'); return messages before that user
 *   message (dropping the launch user message, fork tool call, and
 *   everything after).
 *
 *   If no fork tool call is found, return messages unchanged.
 *   If a fork tool call is found but there is no preceding user message,
 *   slice to the fork-call index.
 *
 *   NEVER mutates the input array — always returns a new list.
 */
final class ForkSnapshotSanitizer
{
    /**
     * Sanitize a list of parent messages for use as fork snapshot.
     *
     * @param list<AgentMessage> $messages The parent message list
     *
     * @return list<AgentMessage> A new list with launch messages removed (if applicable)
     */
    public function sanitize(array $messages): array
    {
        // Scan from the end backward for a fork tool call in an assistant message.
        $count = \count($messages);

        for ($i = $count - 1; $i >= 0; --$i) {
            if (!$this->isAssistantForkToolCall($messages[$i])) {
                continue;
            }

            // Found a fork tool call at index $i — walk backward for the preceding user message.
            for ($j = $i - 1; $j >= 0; --$j) {
                if ('user' === $messages[$j]->role) {
                    // Return everything before this user message (exclusive).
                    return \array_slice($messages, 0, $j);
                }
            }

            // No preceding user message found — slice to the fork-call index.
            return \array_slice($messages, 0, $i);
        }

        // No fork tool call found — return unchanged (new list).
        return \array_slice($messages, 0);
    }

    /**
     * Check if a message is an assistant message whose tool_calls include
     * a tool named 'fork'.
     *
     * @param AgentMessage $message The message to inspect
     */
    private function isAssistantForkToolCall(AgentMessage $message): bool
    {
        if ('assistant' !== $message->role) {
            return false;
        }

        $toolCalls = $message->metadata['tool_calls'] ?? null;

        if (!\is_array($toolCalls)) {
            return false;
        }

        foreach ($toolCalls as $toolCall) {
            if (!\is_array($toolCall)) {
                continue;
            }

            if (isset($toolCall['name']) && 'fork' === $toolCall['name']) {
                return true;
            }
        }

        return false;
    }
}
