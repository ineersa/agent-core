<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Async worker request to invoke the summarization model for compaction.
 *
 * The owner seals the typed summarization and retained-tail messages in an
 * immutable request file. Messenger sends only ExecutionRequest to the LLM
 * transport. The authorization gate resolves this input for the winning claim,
 * without reading execution state or rebuilding conversation history.
 */
final readonly class ExecuteCompactionStep extends AbstractAgentBusMessage
{
    /**
     * @param string                    $runId                   Target run identifier
     * @param int                       $turnNo                  Turn number at dispatch time (for staleness guard)
     * @param string                    $stepId                  Unique step identifier
     * @param int                       $attempt                 Retry attempt counter
     * @param string                    $idempotencyKey          Deterministic dedup key
     * @param string                    $model                   Resolved compaction model ref (provider/model)
     * @param array<string, mixed>      $modelOptions            Opaque model/platform options (e.g. thinking_level); forwarded uninterpreted
     * @param list<AgentMessage>        $summarizationMessages   Messages for the summarization LLM call
     * @param list<AgentMessage>        $retainedTailMessages    Messages kept as-is (retained tail)
     * @param int                       $messagesCompacted       Number of messages being summarized away
     * @param int                       $messagesRetained        Number of messages in the retained tail
     * @param int                       $firstRetainedIndex      Original index of first retained message
     * @param int                       $tokenEstimateBefore     Token estimate before compaction
     * @param string                    $trigger                 Trigger source ('manual' or 'auto')
     * @param bool                      $continueAfterCompaction Propagated from CompactRun
     * @param array<string, mixed>|null $hookMetadata            Sanitised hook metadata to attach to context_compacted payload
     */
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        public string $model,
        public array $modelOptions,
        public array $summarizationMessages,
        public array $retainedTailMessages,
        public int $messagesCompacted,
        public int $messagesRetained,
        public int $firstRetainedIndex,
        public int $tokenEstimateBefore,
        public string $trigger,
        public bool $continueAfterCompaction = false,
        public ?array $hookMetadata = null,
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
