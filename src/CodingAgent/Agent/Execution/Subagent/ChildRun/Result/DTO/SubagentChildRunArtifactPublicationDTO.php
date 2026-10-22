<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Result\DTO;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;

final readonly class SubagentChildRunArtifactPublicationDTO
{
    public function __construct(
        public ChildRunIdentityDTO $identity,
        public AgentArtifactStatusEnum $status,
        public ?string $summary,
        public ?string $failureReason,
        public ?string $needsClarification,
        public string $handoffContent,
        public ?string $handoffId,
    ) {
    }
}
