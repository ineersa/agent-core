<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Doctrine ORM entity for the tool_batch_state table.
 *
 * Uses auto-increment surrogate primary key (id) instead of composite key
 * (runId, turnNo, stepId). Domain uniqueness is enforced by a unique constraint
 * on (run_id, turn_no, step_id) — see migration-generated schema.
 *
 * runId is validated at session/run creation time (see HatfieldSessionStore)
 * to ensure IDs do not collide with existing sessions.
 */
#[ORM\Entity]
#[ORM\Table(
    name: 'tool_batch_state',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'tool_batch_run_step', columns: ['run_id', 'turn_no', 'step_id']),
    ],
)]
class ToolBatchState
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    public private(set) int $id = 0;

    #[ORM\Column(name: 'run_id', type: 'string')]
    public private(set) string $runId = '';

    #[ORM\Column(name: 'turn_no', type: 'integer')]
    public private(set) int $turnNo = 0;

    #[ORM\Column(name: 'step_id', type: 'string')]
    public private(set) string $stepId = '';

    #[ORM\Column(name: 'batch_data', type: 'text')]
    public private(set) string $batchData = '';

    #[ORM\Column(name: 'created_at', type: 'string')]
    public private(set) string $createdAt = '';

    #[ORM\Column(name: 'updated_at', type: 'string')]
    public private(set) string $updatedAt = '';

    /** No-arg constructor for Doctrine hydration. */
    public function __construct()
    {
    }

    /**
     * Create a new tool batch state entity.
     */
    public static function create(
        string $runId,
        int $turnNo,
        string $stepId,
        string $batchData,
        string $createdAt,
        string $updatedAt,
    ): self {
        $entity = new self();
        $entity->runId = $runId;
        $entity->turnNo = $turnNo;
        $entity->stepId = $stepId;
        $entity->batchData = $batchData;
        $entity->createdAt = $createdAt;
        $entity->updatedAt = $updatedAt;

        return $entity;
    }

    /**
     * Update serialized batch data.
     */
    public function setBatchData(string $batchData): void
    {
        $this->batchData = $batchData;
    }

    /**
     * Update timestamp.
     */
    public function setUpdatedAt(string $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    /**
     * Get batch data.
     */
    public function getBatchData(): string
    {
        return $this->batchData;
    }

    /**
     * Get run ID.
     */
    public function getRunId(): string
    {
        return $this->runId;
    }

    /**
     * Get turn number.
     */
    public function getTurnNo(): int
    {
        return $this->turnNo;
    }

    /**
     * Get step ID.
     */
    public function getStepId(): string
    {
        return $this->stepId;
    }

    /**
     * Get created at timestamp.
     */
    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    /**
     * Get updated at timestamp.
     */
    public function getUpdatedAt(): string
    {
        return $this->updatedAt;
    }
}
