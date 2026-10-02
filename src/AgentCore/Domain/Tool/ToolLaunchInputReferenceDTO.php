<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

use Symfony\Component\Serializer\Attribute\Groups;

/** Private runtime payload identity, never a caller-supplied path. */
final readonly class ToolLaunchInputReferenceDTO
{
    public function __construct(
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public string $kind,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public string $producingRunId,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public int $producingTurnNo,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public string $producingStepId,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public string $toolCallId,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public string $producingModel,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public string $sha256,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public int $bytes,
    ) {
    }
}
