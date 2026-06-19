<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * Result of building compacted messages from a summary text and preparation.
 *
 * Returned by {@see SessionCompactor::buildCompactedMessages()}. Contains
 * the summary message (with metadata), the full compacted message list
 * ready to replace the current hot messages, and before/after token estimates.
 */
final readonly class CompactResultDTO
{
    /**
     * @param string             $summaryText         Raw summary text returned by the summarization model
     * @param AgentMessage       $summaryMessage      Injected summary message with compact_summary metadata
     * @param list<AgentMessage> $compactedMessages   Full compacted message list: [summaryMessage, ...retainedTail]
     * @param int                $tokenEstimateBefore Approximate token count before compaction
     * @param int                $tokenEstimateAfter  Approximate token count after compaction
     * @param int                $messagesCompacted   Number of messages summarized away
     * @param int                $messagesRetained    Number of messages in the retained tail (excluding summary)
     * @param int                $firstRetainedIndex  Original index of first message in the retained tail
     */
    public function __construct(
        public string $summaryText,
        public AgentMessage $summaryMessage,
        public array $compactedMessages,
        public int $tokenEstimateBefore,
        public int $tokenEstimateAfter,
        public int $messagesCompacted,
        public int $messagesRetained,
        public int $firstRetainedIndex,
    ) {
    }
}
