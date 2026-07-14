<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;

/**
 * Durable single-child deferred launch projection (Piece 3A).
 *
 * Piece 3B correlates child RunCommit events via parent_run_id + tool_call_id and
 * resolves DeferredToolCompletionRepository after ExecuteToolCallWorker registration.
 */
final readonly class DeferredSingleSubagentProjectionDTO
{
    public function __construct(
        public string $lifecycleId,
        public string $parentRunId,
        public int $parentTurnNo,
        public string $parentToolCallId,
        public int $parentOrderIndex,
        public string $childRunId,
        public string $artifactId,
        public string $agentName,
        public string $task,
        public ?string $definitionModel,
        public DeferredSingleSubagentLaunchStatusEnum $launchStatus,
        public int $childEventCursor,
        public int $parentProgressCursor,
        public ?\DateTimeImmutable $terminalCompletionEnqueuedAt,
        public ?\DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $deadlineAt,
        public \DateTimeImmutable $createdAt,
        public ?DeferredSubagentInterruptionKindEnum $interruptionKind = null,
        public ?\DateTimeImmutable $interruptionRequestedAt = null,
        public ?\DateTimeImmutable $interruptionProgressEnqueuedAt = null,
        public ?DeferredChildRunLifecycleProjectionDTO $childLifecycleProjection = null,
    ) {
    }
}
