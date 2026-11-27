<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Run\DTO\CurrentOperationDTO;
use Ineersa\AgentCore\Domain\Run\DTO\CurrentToolCallDTO;

final readonly class RunState
{
    /**
     * Initializes run state with identifier, status, and progression counters.
     *
     * @param list<AgentMessage>                $messages
     * @param array<string, bool>               $pendingToolCalls
     * @param array<string, bool>               $pendingShellToolCalls     current direct-shell tool call ids only; removed when execution ends
     * @param list<CurrentToolCallDTO>          $currentToolCalls          payload-free current tool coordination only
     * @param list<PendingHumanInputRequestDTO> $pendingHumanInputRequests ordered FIFO of outstanding human-input requests
     */
    public function __construct(
        public string $runId,
        public RunStatus $status,
        public int $version = 0,
        public int $turnNo = 0,
        public int $lastSeq = 0,
        public bool $isStreaming = false,
        public array $pendingToolCalls = [],
        /** @var array<string, bool> Current direct-shell tool call ids only; never a receipt history. */
        public array $pendingShellToolCalls = [],
        /** @var list<CurrentToolCallDTO> Payload-free rows for the current tool batch only. */
        public array $currentToolCalls = [],
        public ?string $errorMessage = null,
        public array $messages = [],
        public ?string $activeStepId = null,
        /** Bounded current operation identity; never an operation history. */
        public ?CurrentOperationDTO $currentOperation = null,
        /** Last committed AdvanceRun token; bounded transition evidence, never a receipt history. */
        public ?string $lastAppliedAdvanceKey = null,
        /** Last completed CompactRun token; bounded transition evidence, never a receipt history. */
        public ?string $lastAppliedCompactionKey = null,
        public array $pendingHumanInputRequests = [],
        /**
         * Canonical execution model for this run (provider/model).
         * Source of truth is run_started.metadata.model.
         * Scheduling and compaction must use this field, never re-resolve session/default.
         */
        public ?string $model = null,
        /**
         * Immediate parent run id from run_started.metadata.session.parent_run_id
         * when session.kind=agent_child; null for top-level runs.
         */
        public ?string $parentRunId = null,
    ) {
    }

    public static function queued(string $runId): self
    {
        return new self(runId: $runId, status: RunStatus::Queued);
    }

    /**
     * LLM results are admitted only for the one exact active model operation.
     * A direct standalone shell can hold that bounded identity while its tool
     * call remains pending, so its forged LLM result must not be accepted.
     */
    public function canAcceptLlmResult(LlmStepResult $message): bool
    {
        return \in_array($this->status, [RunStatus::Running, RunStatus::Cancelling], true)
            && null !== $this->currentOperation
            && $this->currentOperation->matchesMessage($message)
            && !$this->isPendingStandaloneShellOperation($this->currentOperation);
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
     *     pendingToolCalls?: array<string, bool>,
     *     pendingShellToolCalls?: array<string, bool>,
     *     currentToolCalls?: list<CurrentToolCallDTO>,
     *     errorMessage?: string|null,
     *     messages?: list<AgentMessage>,
     *     activeStepId?: string|null,
     *     currentOperation?: CurrentOperationDTO|null,
     *     lastAppliedAdvanceKey?: string|null,
     *     lastAppliedCompactionKey?: string|null,
     *     pendingHumanInputRequests?: list<PendingHumanInputRequestDTO>,
     *     model?: string|null,
     *     parentRunId?: string|null
     * } $overrides
     */
    public function with(array $overrides = []): self
    {
        return clone($this, $overrides);
    }

    /**
     * Persisted direct-shell tool call identity derived from the command
     * idempotency key; replay, repair, indexing and admission must agree on it.
     */
    public static function standaloneShellToolCallId(string $idempotencyKey): string
    {
        return 'sh_'.hash('sha256', $idempotencyKey);
    }

    public function isPendingStandaloneShellOperation(CurrentOperationDTO $operation): bool
    {
        return isset($this->pendingShellToolCalls[self::standaloneShellToolCallId($operation->idempotencyKey)]);
    }
}
