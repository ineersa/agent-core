<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

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
        public ?\DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $deadlineAt,
    ) {
    }
}
