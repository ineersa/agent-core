<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Validator\Constraints as Assert;

/** Latest bounded run coordination projection; canonical payload/history remains in events.jsonl. */
#[ORM\Entity]
#[ORM\Table(name: 'run_operational_state')]
#[ORM\Index(name: 'idx_run_operational_state_owner', columns: ['owner_session_id'])]
#[ORM\Index(name: 'idx_run_operational_state_status', columns: ['status'])]
#[ORM\HasLifecycleCallbacks]
final class RunOperationalState
{
    use TimestampableLifecycleTrait;

    public const int ID_MAX_LENGTH = 255;
    public const int STATUS_MAX_LENGTH = 32;

    #[ORM\Id]
    #[ORM\Column(name: 'run_id', type: 'string', length: self::ID_MAX_LENGTH)]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: self::ID_MAX_LENGTH)]
    public string $runId;

    #[ORM\Column(name: 'parent_run_id', type: 'string', length: self::ID_MAX_LENGTH, nullable: true)]
    #[Assert\Length(min: 1, max: self::ID_MAX_LENGTH)]
    public ?string $parentRunId = null;

    #[ORM\Column(name: 'owner_session_id', type: 'string', length: self::ID_MAX_LENGTH)]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: self::ID_MAX_LENGTH)]
    public string $ownerSessionId;

    #[ORM\Column(type: 'string', length: self::STATUS_MAX_LENGTH, enumType: RunStatus::class)]
    public RunStatus $status;

    #[ORM\Column(name: 'turn_no', type: 'integer')]
    #[Assert\GreaterThanOrEqual(0)]
    public int $turnNo;

    #[ORM\Column(name: 'active_step_id', type: 'string', length: self::ID_MAX_LENGTH, nullable: true)]
    #[Assert\Length(min: 1, max: self::ID_MAX_LENGTH)]
    public ?string $activeStepId;

    #[ORM\Column(name: 'last_applied_advance_key', type: 'string', length: self::ID_MAX_LENGTH, nullable: true)]
    #[Assert\Length(min: 1, max: self::ID_MAX_LENGTH)]
    public ?string $lastAppliedAdvanceKey;

    #[ORM\Column(name: 'last_applied_compaction_key', type: 'string', length: self::ID_MAX_LENGTH, nullable: true)]
    #[Assert\Length(min: 1, max: self::ID_MAX_LENGTH)]
    public ?string $lastAppliedCompactionKey;

    #[ORM\Column(name: 'retryable_failure', type: 'boolean')]
    public bool $retryableFailure;

    #[ORM\Column(name: 'retry_attempts', type: 'integer')]
    #[Assert\GreaterThanOrEqual(0)]
    public int $retryAttempts;

    #[ORM\Column(name: 'last_event_sequence', type: 'integer')]
    #[Assert\GreaterThanOrEqual(0)]
    public int $lastEventSequence;

    #[ORM\Column(name: 'transition_version', type: 'integer')]
    #[Assert\GreaterThanOrEqual(0)]
    public int $transitionVersion;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $updatedAt;

    /** @var Collection<int, RunOperationalToolCall> */
    #[ORM\OneToMany(targetEntity: RunOperationalToolCall::class, mappedBy: 'run', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Assert\Valid]
    public Collection $toolCalls;

    /** @var Collection<int, RunOperationalHumanInput> */
    #[ORM\OneToMany(targetEntity: RunOperationalHumanInput::class, mappedBy: 'run', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Assert\Valid]
    public Collection $humanInputs;

    public function __construct()
    {
        $this->toolCalls = new ArrayCollection();
        $this->humanInputs = new ArrayCollection();
        $now = Clock::get()->now();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }
}
