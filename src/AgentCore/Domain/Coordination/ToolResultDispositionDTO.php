<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Coordination;

/** Replayable disposition of one exact durable worker result, not a new receipt ledger. */
#[\Symfony\Component\Serializer\Attribute\Groups([\Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO::SNAPSHOT_GROUP])]
final readonly class ToolResultDispositionDTO
{
    public function __construct(
        public string $runId,
        public int $turnNo,
        public string $stepId,
        public string $operationId,
        public string $claimToken,
        public string $resultHash,
        public string $disposition,
    ) {
        if (!\in_array($disposition, ['Consumed', 'Stale'], true)) {
            throw new \InvalidArgumentException('Invalid tool result disposition.');
        }
    }
}
