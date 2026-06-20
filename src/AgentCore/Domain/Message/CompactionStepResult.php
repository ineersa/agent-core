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
     * @param string                     $runId                Target run identifier
     * @param int                        $turnNo               Turn number at dispatch time (for staleness guard)
     * @param string                     $stepId               Unique step identifier
     * @param int                        $attempt              Retry attempt counter
     * @param string                     $idempotencyKey       Deterministic dedup key
     * @param string|null                $summaryText          Raw summary text from the model (null on error)
     * @param array<string, mixed>|null  $error                Structured error from the platform (null on success)
     * @param list<array<string, mixed>> $retainedTailMessages Serialized AgentMessage shapes kept as-is (retained tail)
     * @param int                        $messagesCompacted    Number of messages being summarized away
     * @param int                        $messagesRetained     Number of messages in the retained tail
     * @param int                        $firstRetainedIndex   Original index of first retained message
     * @param int                        $tokenEstimateBefore  Token estimate before compaction
     * @param string                     $trigger              Trigger source ('manual' or 'auto')
     * @param string                     $model                Resolved compaction model ref used for invocation
     * @param string|null                $thinkingLevel        Thinking level used for invocation
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
        public string $model,
        public ?string $thinkingLevel,
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
