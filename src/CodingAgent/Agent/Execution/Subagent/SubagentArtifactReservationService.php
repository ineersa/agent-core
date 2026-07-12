<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent;

use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunArtifactLifecyclePort;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\PreparedAgentChildRunDTO;

final class SubagentArtifactReservationService
{
    public function __construct(
        private readonly ChildRunArtifactLifecyclePort $artifactLifecycle,
    ) {
    }

    public function reserve(ChildRunIdentityDTO $identity): void
    {
        $this->artifactLifecycle->reservePending(new PreparedAgentChildRunDTO($identity, new \Ineersa\AgentCore\Domain\Run\StartRunInput(systemPrompt: '', messages: [], runId: $identity->childRunId), artifactReservedPending: true));
    }
}
