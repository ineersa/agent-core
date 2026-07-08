<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * Result of synchronous virtual compaction (fork snapshot, no parent run mutation).
 */
final readonly class VirtualCompactionResult
{
    /**
     * @param list<AgentMessage> $compactedMessages
     */
    public function __construct(
        public array $compactedMessages,
        public bool $compacted,
        public ?string $summaryText = null,
        public int $summarizedCount = 0,
    ) {
    }
}
