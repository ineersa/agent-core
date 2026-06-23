<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Result of an async compaction model invocation.
 *
 * Dispatched back to agent.command.bus (sync, in-process — no transport
 * serialization).  The result handler validates staleness, processes the
 * summary text, and emits either context_compacted or
 * context_compaction_failed.
 */
final readonly class CompactionStepResult extends AbstractAgentBusMessage
{
    /**
     * @param string                     $runId                   Target run identifier
     * @param int                        $turnNo                  Turn number at dispatch time (for staleness guard)
     * @param string                     $stepId                  Unique step identifier
     * @param int                        $attempt                 Retry attempt counter
     * @param string                     $idempotencyKey          Deterministic dedup key
     * @param string|null                $summaryText             Raw summary text from the model (null on error)
     * @param array<string, mixed>|null  $error                   Structured error from the platform (null on success)
     * @param list<array<string, mixed>> $retainedTailMessages    Serialized AgentMessage shapes kept as-is (retained tail)
     * @param int                        $messagesCompacted       Number of messages being summarized away
     * @param int                        $messagesRetained        Number of messages in the retained tail
     * @param int                        $firstRetainedIndex      Original index of first retained message
     * @param int                        $tokenEstimateBefore     Token estimate before compaction
     * @param string                     $trigger                 Trigger source ('manual' or 'auto')
     * @param bool                       $continueAfterCompaction Propagated from ExecuteCompactionStep
     * @param string                     $model                   Resolved compaction model ref used for invocation
     * @param array<string, mixed>       $modelOptions            Opaque model/platform options echoed back from the step (e.g. thinking_level)
     * @param array<string, mixed>|null  $hookMetadata            Sanitised hook metadata echoed from the step for context_compacted payload
     */
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        public ?string $summaryText,
        public ?array $error,
        public array $retainedTailMessages,
        public int $messagesCompacted,
        public int $messagesRetained,
        public int $firstRetainedIndex,
        public int $tokenEstimateBefore,
        public string $trigger,
        public bool $continueAfterCompaction = false,
        public string $model = '',
        public array $modelOptions = [],
        public ?array $hookMetadata = null,
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
