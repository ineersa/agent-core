<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * ORM metadata for the execution_operation schema.
 *
 * DoctrineExecutionOperationStore reads and writes rows through DBAL only.
 * This entity keeps CodingAgent\Entity mapping and migrations aligned; it is
 * not a repository or ownership API. worker_pid is diagnostic context for
 * operators and logs. Ownership proof is the nonexpiring worker-instance lock
 * and claim_lock_key / claim_token receipt, never the process id.
 */
#[ORM\Entity]
#[ORM\Table(name: 'execution_operation')]
#[ORM\Index(name: 'idx_execution_operation_run_state', columns: ['run_id', 'state'])]
class ExecutionOperation
{
    #[ORM\Id]
    #[ORM\Column(name: 'effect_id', type: 'string', length: 64)]
    public string $effectId = '';

    #[ORM\Column(name: 'run_id', type: 'string', length: 255)]
    public string $runId = '';

    #[ORM\Column(name: 'turn_no', type: 'integer')]
    public int $turnNo = 0;

    #[ORM\Column(name: 'step_id', type: 'string', length: 255)]
    public string $stepId = '';

    #[ORM\Column(type: 'integer')]
    public int $attempt = 0;

    #[ORM\Column(name: 'idempotency_key', type: 'string', length: 255)]
    public string $idempotencyKey = '';

    #[ORM\Column(name: 'request_type', type: 'string', length: 255)]
    public string $requestType = '';

    #[ORM\Column(name: 'result_type', type: 'string', length: 255)]
    public string $resultType = '';

    #[ORM\Column(name: 'request_hash', type: 'string', length: 64)]
    public string $requestHash = '';

    #[ORM\Column(name: 'request_bytes', type: 'integer')]
    public int $requestBytes = 0;

    #[ORM\Column(name: 'owner_generation', type: 'string', length: 64)]
    public string $ownerGeneration = '';

    #[ORM\Column(type: 'string', length: 32)]
    public string $state = 'Prepared';

    #[ORM\Column(name: 'claim_token', type: 'string', length: 255, nullable: true)]
    public ?string $claimToken = null;

    #[ORM\Column(name: 'worker_instance', type: 'string', length: 64, nullable: true)]
    public ?string $workerInstance = null;

    /** Diagnostic only. Never treat as ownership or liveness proof. */
    #[ORM\Column(name: 'worker_pid', type: 'integer', nullable: true)]
    public ?int $workerPid = null;

    #[ORM\Column(name: 'claim_lock_key', type: 'string', length: 64, nullable: true)]
    public ?string $claimLockKey = null;

    #[ORM\Column(name: 'unknown_notice_transition', type: 'string', length: 64, nullable: true)]
    public ?string $unknownNoticeTransition = null;

    #[ORM\Column(name: 'result_hash', type: 'string', length: 64, nullable: true)]
    public ?string $resultHash = null;

    #[ORM\Column(name: 'result_bytes', type: 'integer', nullable: true)]
    public ?int $resultBytes = null;

    #[ORM\Column(name: 'disposition_transition', type: 'string', length: 64, nullable: true)]
    public ?string $dispositionTransition = null;

    #[ORM\Column(name: 'logical_tool_call_id', type: 'string', length: 255, nullable: true)]
    public ?string $logicalToolCallId = null;

    #[ORM\Column(name: 'deferred_id', type: 'string', length: 255, nullable: true)]
    public ?string $deferredId = null;
}
