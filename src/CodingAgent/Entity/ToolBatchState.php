<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tool_batch_state')]
class ToolBatchState
{
    #[ORM\Id]
    #[ORM\Column(type: 'string')]
    private string $runId;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $turnNo;

    #[ORM\Id]
    #[ORM\Column(type: 'string')]
    private string $stepId;

    #[ORM\Column(type: 'text')]
    private string $batchData;

    #[ORM\Column(type: 'string')]
    private string $createdAt;

    #[ORM\Column(type: 'string')]
    private string $updatedAt;

    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        string $batchData,
        string $createdAt,
        string $updatedAt,
    ) {
        $this->runId = $runId;
        $this->turnNo = $turnNo;
        $this->stepId = $stepId;
        $this->batchData = $batchData;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public function getRunId(): string
    {
        return $this->runId;
    }

    public function getTurnNo(): int
    {
        return $this->turnNo;
    }

    public function getStepId(): string
    {
        return $this->stepId;
    }

    public function getBatchData(): string
    {
        return $this->batchData;
    }

    public function setBatchData(string $batchData): void
    {
        $this->batchData = $batchData;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): string
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(string $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }
}
