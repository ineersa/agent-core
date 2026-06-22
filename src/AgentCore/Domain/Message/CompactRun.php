<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Request to compact the current run's message history.
 *
 * Dispatched to agent.command.bus. The handler loads the run state,
 * calls into the compaction service for preparation, and either emits
 * a failure event or dispatches an ExecuteCompactionStep for async
 * model invocation.
 */
final readonly class CompactRun extends AbstractAgentBusMessage
{
    /**
     * @param string      $runId                   Target run identifier
     * @param int         $turnNo                  Current turn number (from runtime/session state at dispatch time)
     * @param string      $stepId                  Unique step identifier for idempotency
     * @param int         $attempt                 Retry attempt counter
     * @param string      $idempotencyKey          Deterministic dedup key
     * @param string      $trigger                 Compaction trigger source — 'manual' for /compact, 'auto' for threshold-based
     * @param bool        $continueAfterCompaction When true, the compaction holds a pending LLM turn and an
     *                                             AdvanceRun effect must follow after successful compaction so
     *                                             the conversation continues (pre-LLM guard path). When false,
     *                                             compaction is maintenance — the run is already terminal and
     *                                             must NOT auto-continue (after-turn hook / manual /compact).
     * @param string|null $customInstructions      Optional user-provided summarization instructions
     */
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        public string $trigger = 'manual',
        public bool $continueAfterCompaction = false,
        public ?string $customInstructions = null,
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
