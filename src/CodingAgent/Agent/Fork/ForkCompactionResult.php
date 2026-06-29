<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * Result of virtual compaction for a fork snapshot.
 *
 * Contains the (possibly compacted) message list, whether compaction
 * occurred, the summary text, and how many messages were summarized.
 */
final readonly class ForkCompactionResult
{
    /**
     * @param list<AgentMessage> $messages        Retained messages after virtual compaction
     * @param bool               $compacted       Whether compaction was actually performed
     * @param string|null        $summaryText     The summary text (null when not compacted)
     * @param int                $summarizedCount Number of messages summarized away (0 when not compacted)
     */
    public function __construct(
        public array $messages,
        public bool $compacted,
        public ?string $summaryText = null,
        public int $summarizedCount = 0,
    ) {
    }
}
