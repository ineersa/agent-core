<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildLaunchStatusEnum;

#[ORM\Entity]
#[ORM\Table(name: 'deferred_subagent_child')]
#[ORM\UniqueConstraint(name: 'uniq_deferred_subagent_child_run', columns: ['child_run_id'])]
#[ORM\UniqueConstraint(name: 'uniq_deferred_subagent_child_batch_index', columns: ['batch_lifecycle_id', 'batch_index'])]
#[ORM\Index(name: 'idx_deferred_subagent_child_batch', columns: ['batch_lifecycle_id'])]
#[ORM\HasLifecycleCallbacks]
class DeferredSubagentChild
{
    use TimestampableLifecycleTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    public int $id = 0;

    #[ORM\Column(name: 'batch_lifecycle_id', type: 'string', length: 36)]
    public string $batchLifecycleId = '';

    #[ORM\Column(name: 'batch_index', type: 'integer')]
    public int $batchIndex = 0;

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

    #[ORM\Column(name: 'artifact_kind', type: 'string', length: 32, enumType: AgentArtifactKindEnum::class)]
    public AgentArtifactKindEnum $artifactKind = AgentArtifactKindEnum::Subagent;

    #[ORM\Column(name: 'launch_status', type: 'string', length: 32, enumType: DeferredSubagentChildLaunchStatusEnum::class)]
    public DeferredSubagentChildLaunchStatusEnum $launchStatus = DeferredSubagentChildLaunchStatusEnum::Reserved;

    #[ORM\Column(name: 'child_event_cursor', type: 'integer')]
    public int $childEventCursor = 0;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'child_lifecycle_projection', type: 'json', nullable: true)]
    public ?array $childLifecycleProjection = null;

    #[ORM\Version]
    #[ORM\Column(name: 'projection_version', type: 'integer')]
    public int $projectionVersion = 1;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'terminal_completed_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $terminalCompletedAt = null;

    #[ORM\Column(name: 'terminal_status', type: 'string', length: 32, nullable: true)]
    public ?string $terminalStatus = null;

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
