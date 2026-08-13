<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * On-disk envelope for one tool-batch snapshot file.
 */
final readonly class ToolBatchSnapshotEnvelopeDTO
{
    public function __construct(
        #[SerializedName('run_id')]
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        #[Assert\NotBlank]
        public string $runId,
        #[SerializedName('turn_no')]
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public int $turnNo,
        #[SerializedName('step_id')]
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        #[Assert\NotBlank]
        public string $stepId,
        #[SerializedName('batch_state')]
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        #[Assert\Valid]
        public ToolBatchStateDTO $batchState,
    ) {
    }
}
