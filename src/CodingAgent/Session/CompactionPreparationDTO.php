<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * Result of {@see SessionCompactor::prepare()}.
 *
 * Contains the two partitions of the message list — messages to summarize
 * and the retained tail — plus token estimates, counts, and whether a
 * prior compact summary already exists in the messages.
 */
final readonly class CompactionPreparationDTO
{
    /**
     * @param list<AgentMessage> $messagesToSummarize  Older messages to be replaced by a summary
     * @param list<AgentMessage> $retainedTailMessages Most recent messages kept as-is
     * @param int                $tokenEstimateBefore  Approximate token count before compaction
     * @param int                $messagesCompacted    Number of messages being summarized away
     * @param int                $messagesRetained     Number of messages kept in the tail
     * @param int                $firstRetainedIndex   Index of first message in the retained tail
     * @param bool               $priorSummaryPresent  Whether the input messages already contain a prior compact summary
     */
    public function __construct(
        public array $messagesToSummarize,
        public array $retainedTailMessages,
        public int $tokenEstimateBefore,
        public int $messagesCompacted,
        public int $messagesRetained,
        public int $firstRetainedIndex,
        public bool $priorSummaryPresent,
    ) {
    }
}
