<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection;

use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;

/**
 * Durable batch projection with ordered child rows (Piece 4A).
 */
final readonly class DeferredSubagentBatchProjectionDTO
{
    /**
     * @param list<DeferredSubagentChildProjectionDTO> $children
     */
    public function __construct(
        public string $lifecycleId,
        public string $parentRunId,
        public int $parentTurnNo,
        public string $parentToolCallId,
        public int $parentOrderIndex,
        public ChildRunBatchExecutionModeEnum $executionMode,
        public int $totalChildCount,
        public DeferredSubagentBatchLaunchStatusEnum $launchStatus,
        public int $aggregateProgressRevision,
        public int $deliveredProgressRevision,
        public ?\DateTimeImmutable $terminalCompletionEnqueuedAt,
        public ?\DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $deadlineAt,
        public \DateTimeImmutable $createdAt,
        public int $projectionVersion,
        public array $children,
        public ?DeferredSubagentInterruptionKindEnum $interruptionKind = null,
        public ?\DateTimeImmutable $interruptionRequestedAt = null,
        public ?\DateTimeImmutable $interruptionProgressEnqueuedAt = null,
        public ?string $parentModel = null,
    ) {
    }
}
