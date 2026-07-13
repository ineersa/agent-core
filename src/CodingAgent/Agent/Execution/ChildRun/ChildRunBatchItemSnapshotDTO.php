<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;

/**
 * Mutable working snapshot for one child in an active batch supervision loop.
 *
 * Owned and updated by batch launch/transition/interruption coordinators until terminal.
 */
final class ChildRunBatchItemSnapshotDTO
{
    public function __construct(
        public readonly ChildRunIdentityDTO $identity,
        public bool $terminal,
        public ?AgentArtifactStatusEnum $artifactStatus,
        public string $message,
    ) {
    }

    public function markTerminalFailed(string $message): void
    {
        $this->terminal = true;
        $this->artifactStatus = AgentArtifactStatusEnum::Failed;
        $this->message = $message;
    }

    public function markTerminalFromArtifactStatus(AgentArtifactStatusEnum $status, string $message): void
    {
        $this->terminal = true;
        $this->artifactStatus = $status;
        $this->message = $message;
    }

    public function markTerminalCancelled(string $message): void
    {
        $this->terminal = true;
        $this->artifactStatus = AgentArtifactStatusEnum::Cancelled;
        $this->message = $message;
    }

    public function markNeedsClarification(): void
    {
        $this->artifactStatus = AgentArtifactStatusEnum::NeedsClarification;
    }

    public function markRunning(): void
    {
        $this->artifactStatus = AgentArtifactStatusEnum::Running;
    }
}
