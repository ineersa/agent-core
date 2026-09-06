<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/** Generated instructions are separate from conversation and compaction messages. */
final class GeneratedContext
{
    /**
     * @param list<AgentMessage> $history
     * @param list<AgentMessage> $context
     *
     * @return list<AgentMessage>
     */
    public static function replace(array $history, array $context): array
    {
        foreach ($history as $index => $message) {
            if ((0 === $index && 'system' === $message->role)
                || ('user-context' === $message->role && \in_array($message->metadata['source'] ?? null, ['agents_context', 'skills_context', 'agents_definitions_context'], true))) {
                continue;
            }
            $context[] = $message;
        }

        return $context;
    }
}
