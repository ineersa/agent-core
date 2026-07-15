<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;

final readonly class ChildRunTerminalOutcomeDTO
{
    public function __construct(
        public ChildRunIdentityDTO $identity,
        public AgentArtifactStatusEnum $status,
        public ?string $summary = null,
        public ?string $failureReason = null,
        public ?string $needsClarification = null,
        public ?RunState $childState = null,
    ) {
    }
}
