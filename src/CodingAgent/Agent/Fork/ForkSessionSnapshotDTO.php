<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * Result of building a fork session snapshot.
 *
 * Contains the sanitized + virtually-compacted seed messages ending at
 * the point the child should continue, the FORK_CHILD system-prompt
 * append text, and the generated Pi-style fork task user message.
 *
 * The child receives:
 *   1. A system-prompt append (FORK_CHILD mode declaration).
 *   2. The snapshot messages as history.
 *   3. A single large generated user message containing the task.
 *
 * NOTE: The snapshot operates on the message list and does NOT serialize
 * to a child events.jsonl file — that is delegated to FORK-03/04.
 */
final readonly class ForkSessionSnapshotDTO
{
    /**
     * @param list<AgentMessage> $messages               Sanitized + virtually-compacted seed messages
     * @param string             $forkSystemPromptAppend System-prompt append text for FORK_CHILD mode
     * @param string             $forkTaskUserMessage    Generated Pi-style fork task user message text
     * @param string|null        $resolvedModel          Resolved model override (null = session model)
     */
    public function __construct(
        public array $messages,
        public string $forkSystemPromptAppend,
        public string $forkTaskUserMessage,
        public ?string $resolvedModel = null,
    ) {
    }
}
