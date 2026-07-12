<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;

final class ChildRunBatchItemSnapshotDTO
{
    public function __construct(
        public readonly ChildRunIdentityDTO $identity,
        public bool $terminal,
        public ?AgentArtifactStatusEnum $artifactStatus,
        public string $message,
    ) {
    }
}
