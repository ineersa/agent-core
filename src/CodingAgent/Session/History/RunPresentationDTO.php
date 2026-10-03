<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

use Ineersa\AgentCore\Domain\Run\RunStatus;

final readonly class RunPresentationDTO
{
    /** @param list<string> $historyLines */
    public function __construct(
        public RunStatus $status,
        public int $turnNo,
        public int $lastSeq,
        public int $eventCount,
        public int $messageCount,
        public int $pendingToolCallCount,
        public ?string $firstPendingToolCallId,
        public string $assistantExcerpt,
        public bool $includeAssistantExcerpt,
        public bool $hasAssistantMessage,
        public int $eligibleMessageCount,
        public array $historyLines,
    ) {
    }
}
