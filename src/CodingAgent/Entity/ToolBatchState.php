<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Doctrine ORM entity for the tool_batch_state table.
 *
 * Uses auto-increment surrogate primary key. Domain uniqueness is
 * enforced by a unique index on (run_id, turn_no, step_id).
 *
 * Mapped fields are public for Doctrine hydration. Property hooks
 * are not yet supported by ORM 3.6 for mapped fields (the UnitOfWork
 * attempts to unset hooked properties during entity removal):
 * https://github.com/doctrine/orm/issues/11624
 *
 * updateBatchData() is the single intentional mutation point.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tool_batch_state')]
class ToolBatchState
{
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
     * Update serialized batch data and bump timestamp.
     */
    public function updateBatchData(string $batchData, string $updatedAt): void
    {
        $this->batchData = $batchData;
        $this->updatedAt = $updatedAt;
    }
}
