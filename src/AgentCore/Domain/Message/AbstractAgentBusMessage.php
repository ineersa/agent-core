<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Bus envelope fields shared by execution messages.
 *
 * Snapshot serialization includes these fields so nested ExecuteToolCall /
 * ToolCallResult objects reconstruct without rebinding.
 * Messenger/PhpSerializer is unaffected by Serializer attributes.
 */
abstract readonly class AbstractAgentBusMessage
{
    public function __construct(
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        private string $runId,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        private int $turnNo,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        private string $stepId,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        private int $attempt,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        private string $idempotencyKey,
    ) {
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function turnNo(): int
    {
        return $this->turnNo;
    }

    public function stepId(): string
    {
        return $this->stepId;
    }

    public function attempt(): int
    {
        return $this->attempt;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }
}
