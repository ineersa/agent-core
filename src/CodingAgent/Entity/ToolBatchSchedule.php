<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * ORM metadata for tool_batch_schedule.
 *
 * DoctrineToolBatchStore reads and writes rows through DBAL only. This entity
 * keeps CodingAgent\Entity mapping and migrations aligned; it is not a
 * repository or ownership API. Invocation bodies and sealed results live in
 * the common execution ledger; this table owns membership, queue state, and
 * current invocation/result references only.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tool_batch_schedule')]
#[ORM\Index(name: 'idx_tool_batch_schedule_run', columns: ['run_id'])]
class ToolBatchSchedule
{
    #[ORM\Id]
    #[ORM\Column(name: 'run_id', type: 'string', length: 255)]
    public string $runId = '';

    #[ORM\Id]
    #[ORM\Column(name: 'turn_no', type: 'integer')]
    public int $turnNo = 0;

    #[ORM\Id]
    #[ORM\Column(name: 'step_id', type: 'string', length: 255)]
    public string $stepId = '';

    #[ORM\Column(name: 'max_parallelism', type: 'integer')]
    public int $maxParallelism = 1;

    #[ORM\Column(type: 'boolean')]
    public bool $finalized = false;

    /** JSON object: tool_call_id => order_index */
    #[ORM\Column(name: 'expected_order_json', type: 'text')]
    public string $expectedOrderJson = '{}';

    /** JSON list of pending tool_call_id values */
    #[ORM\Column(name: 'pending_queue_json', type: 'text')]
    public string $pendingQueueJson = '[]';

    /** JSON list of in-flight tool_call_id values */
    #[ORM\Column(name: 'in_flight_json', type: 'text')]
    public string $inFlightJson = '[]';

    /** JSON object: tool_call_id => question_id */
    #[ORM\Column(name: 'awaiting_human_input_json', type: 'text')]
    public string $awaitingHumanInputJson = '{}';

    /**
     * JSON object: tool_call_id => {attempt, idempotency_key, effect_id, request_hash}.
     * Bodies remain sealed on the common invocation ledger.
     */
    #[ORM\Column(name: 'calls_json', type: 'text')]
    public string $callsJson = '{}';

    /**
     * JSON object: tool_call_id => {attempt, idempotency_key, effect_id, claim_token, result_hash, bytes}.
     * Bodies remain sealed on the common invocation ledger.
     */
    #[ORM\Column(name: 'results_json', type: 'text')]
    public string $resultsJson = '{}';

    /** Last verified transition that applied this row; empty when none. */
    #[ORM\Column(name: 'applied_transition', type: 'string', length: 64)]
    public string $appliedTransition = '';
}
