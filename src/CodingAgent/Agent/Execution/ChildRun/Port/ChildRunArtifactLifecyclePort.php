<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Port;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\PreparedAgentChildRunDTO;

interface ChildRunArtifactLifecyclePort
{
    public function reservePending(PreparedAgentChildRunDTO $prepared): void;

    public function markRunning(ChildRunIdentityDTO $identity): void;

    public function markNeedsClarification(ChildRunIdentityDTO $identity): void;

    public function clearNeedsClarificationToRunning(ChildRunIdentityDTO $identity): void;

    public function getArtifactStatus(string $parentRunId, string $artifactId): ?AgentArtifactStatusEnum;

    public function hasRegistryEntry(string $parentRunId, string $artifactId): bool;

    public function removePendingRegistryEntry(ChildRunIdentityDTO $identity): void;
}
