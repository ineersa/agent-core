<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

final readonly class RunState
{
    /**
     * Initializes run state with identifier, status, and progression counters.
     *
     * @param list<AgentMessage>                $messages
     * @param array<string, bool>               $pendingToolCalls
     * @param array<string, mixed>|null         $streamingMessage
     * @param list<PendingHumanInputRequestDTO> $pendingHumanInputRequests ordered FIFO of outstanding human-input requests
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
        public ?string $activeStepId = null,
        public bool $retryableFailure = false,
        /** Count of completed auto-retry attempts in the active retryable-failure episode; manual continue resets to 0. May be one past max when retries are exhausted. */
        public int $retryAttempts = 0,
        public array $pendingHumanInputRequests = [],
    ) {
    }

    public static function queued(string $runId): self
    {
        return new self(runId: $runId, status: RunStatus::Queued);
    }
}
