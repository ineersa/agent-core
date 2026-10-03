<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/** Generated instructions are separate from conversation and compaction messages. */
final class GeneratedContext
{
    public static function isGeneratedUserContext(AgentMessage $message): bool
    {
        return 'user-context' === $message->role && \in_array($message->metadata['source'] ?? null, ['agents_context', 'skills_context', 'agents_definitions_context'], true);
    }

    /**
     * @param list<AgentMessage> $history
     * @param list<AgentMessage> $context
     *
     * @return list<AgentMessage>
     */
    public static function replace(array $history, array $context): array
    {
        // Start and compaction both keep the generated system message at index zero.
        foreach ($history as $index => $message) {
            if ((0 === $index && 'system' === $message->role)
                || self::isGeneratedUserContext($message)) {
                continue;
            }
            $context[] = $message;
        }

        return $context;
    }
}
