<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ineersa\CodingAgent\Agent\Execution\DeferredSingleSubagentLaunchStatusEnum;

#[ORM\Entity]
#[ORM\Table(name: 'deferred_single_subagent_launch')]
#[ORM\UniqueConstraint(name: 'uniq_deferred_single_subagent_parent_tool', columns: ['parent_run_id', 'parent_tool_call_id'])]
#[ORM\HasLifecycleCallbacks]
class DeferredSingleSubagentLaunch
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

    #[ORM\Column(name: 'child_run_id', type: 'string', length: 36, unique: true)]
    public string $childRunId = '';

    #[ORM\Column(name: 'artifact_id', type: 'string', length: 64)]
    public string $artifactId = '';

    #[ORM\Column(name: 'agent_name', type: 'string', length: 255)]
    public string $agentName = '';

    #[ORM\Column(type: 'text')]
    public string $task = '';

    #[ORM\Column(name: 'definition_model', type: 'string', length: 255, nullable: true)]
    public ?string $definitionModel = null;

    #[ORM\Column(name: 'launch_status', type: 'string', length: 32, enumType: DeferredSingleSubagentLaunchStatusEnum::class)]
    public DeferredSingleSubagentLaunchStatusEnum $launchStatus = DeferredSingleSubagentLaunchStatusEnum::Reserved;

    #[ORM\Column(name: 'child_event_cursor', type: 'integer')]
    public int $childEventCursor = 0;

    #[ORM\Column(name: 'parent_progress_cursor', type: 'integer')]
    public int $parentProgressCursor = 0;

    #[ORM\Column(name: 'terminal_completion_enqueued_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $terminalCompletionEnqueuedAt = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'child_lifecycle_projection', type: 'json', nullable: true)]
    public ?array $childLifecycleProjection = null;

    #[ORM\Version]
    #[ORM\Column(name: 'projection_version', type: 'integer')]
    public int $projectionVersion = 1;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'deadline_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $deadlineAt = null;

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
