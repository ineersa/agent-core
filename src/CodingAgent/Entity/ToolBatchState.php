<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

/**
 * Doctrine ORM entity for the tool_batch_state table.
 *
 * Uses auto-increment surrogate primary key. Domain uniqueness is
 * enforced by a DB unique constraint and Symfony validation constraint
 * on (run_id, turn_no, step_id).
 *
 * Mapped fields are public for Doctrine hydration. Property hooks
 * are not yet supported by ORM 3.6 for mapped fields:
 * https://github.com/doctrine/orm/issues/11624
 *
 * created_at / updated_at are maintained by TimestampableLifecycleTrait.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tool_batch_state')]
#[ORM\UniqueConstraint(name: 'tool_batch_run_step', columns: ['run_id', 'turn_no', 'step_id'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['runId', 'turnNo', 'stepId'], message: 'A batch state for this run, turn, and step already exists.')]
class ToolBatchState
{
    use TimestampableLifecycleTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    public int $id = 0;

    #[ORM\Column(name: 'run_id', type: 'string')]
    public string $runId = '';

    #[ORM\Column(name: 'turn_no', type: 'integer')]
    public int $turnNo = 0;

    #[ORM\Column(name: 'step_id', type: 'string')]
    public string $stepId = '';

    #[ORM\Column(name: 'batch_data', type: 'text')]
    public string $batchData = '';

    #[ORM\Column(name: 'created_at', type: 'string')]
    public string $createdAt = '';

    #[ORM\Column(name: 'updated_at', type: 'string')]
    public string $updatedAt = '';

    /** No-arg constructor for Doctrine hydration. */
    public function __construct()
    {
    }

    /**
     * Update serialized batch data. Timestamps are maintained by
     * TimestampableLifecycleTrait via lifecycle callbacks.
     */
    public function updateBatchData(string $batchData): void
    {
        $this->batchData = $batchData;
    }
}
