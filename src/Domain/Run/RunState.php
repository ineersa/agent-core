<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * Immutable snapshot of a run's current state — status, conversation messages, progression counters, and pending tool calls.
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

    public static function queued(string $runId): self
    {
        return new self(runId: $runId, status: RunStatus::Queued);
    }
}
