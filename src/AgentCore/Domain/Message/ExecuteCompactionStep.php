<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Async worker request to invoke the summarization model for compaction.
 *
 * Dispatched to agent.execution.bus → llm transport.  To stay compatible
 * with the default Symfony Serializer (which llm transport uses),
 * all properties are scalar or array-of-scalar; complex objects like
 * AgentMessage are carried as their toArray() shapes and reconstructed
 * by the worker.
 *
 * Note: summarizationMessages and retainedTailMessages carry full
 * serialized message content (toArray() output) because compaction
 * operates on the raw conversation history, unlike ExecuteLlmStep which
 * uses a runId-backed deferred resolution.  The serialized payload is
 * bounded by keep_recent_tokens (~20k tokens ≈ 65KB of message JSON)
 * and the summarization window.
 */
final readonly class ExecuteCompactionStep extends AbstractAgentBusMessage
{
    /**
     * @param string                     $runId                 Target run identifier
     * @param int                        $turnNo                Turn number at dispatch time (for staleness guard)
     * @param string                     $stepId                Unique step identifier
     * @param int                        $attempt               Retry attempt counter
     * @param string                     $idempotencyKey        Deterministic dedup key
     * @param string                     $model                 Resolved compaction model ref (provider/model)
     * @param string|null                $thinkingLevel         Thinking/reasoning level override (null = session default)
     * @param list<array<string, mixed>> $summarizationMessages Serialized AgentMessage shapes for the summarization LLM call
     * @param list<array<string, mixed>> $retainedTailMessages  Serialized AgentMessage shapes kept as-is (retained tail)
     * @param int                        $messagesCompacted     Number of messages being summarized away
     * @param int                        $messagesRetained      Number of messages in the retained tail
     * @param int                        $firstRetainedIndex    Original index of first retained message
     * @param int                        $tokenEstimateBefore   Token estimate before compaction
     * @param string                     $trigger               Trigger source ('manual' or 'auto')
     */
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        public string $model,
        public ?string $thinkingLevel,
        public array $summarizationMessages,
        public array $retainedTailMessages,
        public int $messagesCompacted,
        public int $messagesRetained,
        public int $firstRetainedIndex,
        public int $tokenEstimateBefore,
        public string $trigger,
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
