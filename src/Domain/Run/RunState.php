<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

final readonly class RunState
{
    /**
     * @param list<AgentMessage>  $messages
     * @param array<string, bool> $pendingToolCalls
     */
    public function __construct(
        public string $runId,
        public RunStatus $status,
        public int $version = 0,
        public int $turnNo = 0,
        public int $lastSeq = 0,
        public bool $isStreaming = false,
        public ?array $streamingMessage = null,
        public array $pendingToolCalls = [],
        public ?string $errorMessage = null,
        public array $messages = [],
    ) {
    }

    public static function queued(string $runId): self
    {
        return new self(runId: $runId, status: RunStatus::Queued);
    }
}
