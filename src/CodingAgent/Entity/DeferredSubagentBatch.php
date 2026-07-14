<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;

#[ORM\Entity]
#[ORM\Table(name: 'deferred_subagent_batch')]
#[ORM\UniqueConstraint(name: 'uniq_deferred_subagent_batch_parent_tool', columns: ['parent_run_id', 'parent_tool_call_id'])]
#[ORM\HasLifecycleCallbacks]
class DeferredSubagentBatch
{
    use TimestampableLifecycleTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    public int $id = 0;

    #[ORM\Column(name: 'lifecycle_id', type: 'string', length: 36, unique: true)]
    public string $lifecycleId = '';

    #[ORM\Column(name: 'parent_run_id', type: 'string', length: 255)]
    public string $parentRunId = '';

    #[ORM\Column(name: 'parent_turn_no', type: 'integer')]
    public int $parentTurnNo = 0;

    #[ORM\Column(name: 'parent_tool_call_id', type: 'string', length: 255)]
    public string $parentToolCallId = '';

    #[ORM\Column(name: 'parent_order_index', type: 'integer')]
    public int $parentOrderIndex = 0;

    #[ORM\Column(name: 'execution_mode', type: 'string', length: 16, enumType: ChildRunBatchExecutionModeEnum::class)]
    public ChildRunBatchExecutionModeEnum $executionMode = ChildRunBatchExecutionModeEnum::Parallel;

    #[ORM\Column(name: 'total_child_count', type: 'integer')]
    public int $totalChildCount = 0;

    #[ORM\Column(name: 'launch_status', type: 'string', length: 32, enumType: DeferredSubagentBatchLaunchStatusEnum::class)]
    public DeferredSubagentBatchLaunchStatusEnum $launchStatus = DeferredSubagentBatchLaunchStatusEnum::Reserved;

    #[ORM\Column(name: 'aggregate_progress_revision', type: 'integer')]
    public int $aggregateProgressRevision = 0;

    #[ORM\Column(name: 'delivered_progress_revision', type: 'integer')]
    public int $deliveredProgressRevision = 0;

    #[ORM\Column(name: 'terminal_completion_enqueued_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $terminalCompletionEnqueuedAt = null;

    #[ORM\Version]
    #[ORM\Column(name: 'projection_version', type: 'integer')]
    public int $projectionVersion = 1;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'deadline_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $deadlineAt = null;

    #[ORM\Column(name: 'interruption_kind', type: 'string', length: 32, enumType: DeferredSubagentInterruptionKindEnum::class, nullable: true)]
    public ?DeferredSubagentInterruptionKindEnum $interruptionKind = null;

    #[ORM\Column(name: 'interruption_requested_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $interruptionRequestedAt = null;

    #[ORM\Column(name: 'interruption_progress_enqueued_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $interruptionProgressEnqueuedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }
}
