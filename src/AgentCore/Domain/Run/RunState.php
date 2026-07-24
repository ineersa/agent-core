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
        /**
         * Canonical execution model for this run (provider/model).
         * Source of truth is run_started.metadata.model, then model_changed transitions.
         * Scheduling and compaction must use this field, never re-resolve session/default.
         */
        public ?string $model = null,
    ) {
    }

    public static function queued(string $runId): self
    {
        return new self(runId: $runId, status: RunStatus::Queued);
    }

    /**
     * Safe immutable copy that preserves every field unless explicitly overridden.
     * Prefer this over raw `new RunState(...)` when only a subset of fields change.
     *
     * @param array{
     *     runId?: string,
     *     status?: RunStatus,
     *     version?: int,
     *     turnNo?: int,
     *     lastSeq?: int,
     *     isStreaming?: bool,
     *     streamingMessage?: array<string, mixed>|null,
     *     pendingToolCalls?: array<string, bool>,
     *     errorMessage?: string|null,
     *     messages?: list<AgentMessage>,
     *     activeStepId?: string|null,
     *     retryableFailure?: bool,
     *     retryAttempts?: int,
     *     pendingHumanInputRequests?: list<PendingHumanInputRequestDTO>,
     *     model?: string|null
     * } $overrides
     */
    public function with(array $overrides = []): self
    {
        return new self(
            runId: \array_key_exists('runId', $overrides) ? (string) $overrides['runId'] : $this->runId,
            status: \array_key_exists('status', $overrides) ? $overrides['status'] : $this->status,
            version: \array_key_exists('version', $overrides) ? (int) $overrides['version'] : $this->version,
            turnNo: \array_key_exists('turnNo', $overrides) ? (int) $overrides['turnNo'] : $this->turnNo,
            lastSeq: \array_key_exists('lastSeq', $overrides) ? (int) $overrides['lastSeq'] : $this->lastSeq,
            isStreaming: \array_key_exists('isStreaming', $overrides) ? (bool) $overrides['isStreaming'] : $this->isStreaming,
            streamingMessage: \array_key_exists('streamingMessage', $overrides) ? $overrides['streamingMessage'] : $this->streamingMessage,
            pendingToolCalls: \array_key_exists('pendingToolCalls', $overrides) ? $overrides['pendingToolCalls'] : $this->pendingToolCalls,
            errorMessage: \array_key_exists('errorMessage', $overrides) ? $overrides['errorMessage'] : $this->errorMessage,
            messages: \array_key_exists('messages', $overrides) ? $overrides['messages'] : $this->messages,
            activeStepId: \array_key_exists('activeStepId', $overrides) ? $overrides['activeStepId'] : $this->activeStepId,
            retryableFailure: \array_key_exists('retryableFailure', $overrides) ? (bool) $overrides['retryableFailure'] : $this->retryableFailure,
            retryAttempts: \array_key_exists('retryAttempts', $overrides) ? (int) $overrides['retryAttempts'] : $this->retryAttempts,
            pendingHumanInputRequests: \array_key_exists('pendingHumanInputRequests', $overrides) ? $overrides['pendingHumanInputRequests'] : $this->pendingHumanInputRequests,
            model: \array_key_exists('model', $overrides) ? $overrides['model'] : $this->model,
        );
    }
}
