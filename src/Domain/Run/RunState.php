<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * Represents the immutable state of a run within the agent core domain, encapsulating its identifier, current status, and progression metrics. This readonly value object ensures state consistency by preventing mutation after construction.
 */
final readonly class RunState
{
    /**
     * Initializes run state with identifier, status, and progression counters.
     *
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
        public ?string $activeStepId = null,
        public bool $retryableFailure = false,
    ) {
    }

    /**
     * Creates a new run state instance initialized to queued status.
     */
    public static function queued(string $runId): self
    {
        return new self(runId: $runId, status: RunStatus::Queued);
    }
}
