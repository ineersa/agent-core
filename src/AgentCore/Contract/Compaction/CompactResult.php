<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Compaction;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * Result of building compacted messages from a summary text.
 *
 * Returned by CompactionServiceInterface::buildCompactedMessages().
 * Contains the full compacted message list ready to replace RunState.messages
 * and after/before token estimates.
 */
final readonly class CompactResult
{
    /**
     * @param list<AgentMessage> $compactedMessages   Full compacted message list: [summaryMessage, ...retainedTail]
     * @param int                $tokenEstimateBefore Approximate token count before compaction
     * @param int                $tokenEstimateAfter  Approximate token count after compaction
     * @param int                $messagesCompacted   Number of messages summarized away
     * @param int                $messagesRetained    Number of messages in the retained tail (excluding summary)
     * @param int                $firstRetainedIndex  Original index of first message in the retained tail
     */
    public function __construct(
        public array $compactedMessages,
        public int $tokenEstimateBefore,
        public int $tokenEstimateAfter,
        public int $messagesCompacted,
        public int $messagesRetained,
        public int $firstRetainedIndex,
    ) {
    }
}
