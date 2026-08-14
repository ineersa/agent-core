<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * On-disk envelope for one tool-batch snapshot file.
 */
final readonly class ToolBatchSnapshotEnvelopeDTO
{
    public function __construct(
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        #[Assert\NotBlank]
        public string $runId,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public int $turnNo,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        #[Assert\NotBlank]
        public string $stepId,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        #[Assert\Valid]
        public ToolBatchStateDTO $batchState,
    ) {
    }
}
