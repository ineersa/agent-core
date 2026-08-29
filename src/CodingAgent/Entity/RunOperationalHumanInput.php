<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ineersa\AgentCore\Domain\Run\HumanInputContinuationKindEnum;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Validator\Constraints as Assert;

/** Current human-input coordination only; question and answer payload remain canonical. */
#[ORM\Entity]
#[ORM\Table(name: 'run_operational_human_input')]
#[ORM\Index(name: 'idx_run_operational_human_current', columns: ['run_id', 'status', 'order_index'])]
#[ORM\HasLifecycleCallbacks]
final class RunOperationalHumanInput
{
    use TimestampableLifecycleTrait;

    public const string STATUS_WAITING = 'waiting';

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: RunOperationalState::class, inversedBy: 'humanInputs')]
    #[ORM\JoinColumn(name: 'run_id', referencedColumnName: 'run_id', nullable: false, onDelete: 'CASCADE')]
    public RunOperationalState $run;

    #[ORM\Id]
    #[ORM\Column(name: 'question_id', type: 'string', length: RunOperationalState::ID_MAX_LENGTH)]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: RunOperationalState::ID_MAX_LENGTH)]
    public string $questionId;

    #[ORM\Column(name: 'order_index', type: 'integer')]
    #[Assert\GreaterThanOrEqual(0)]
    public int $orderIndex;

    #[ORM\Column(name: 'continuation_kind', type: 'string', length: RunOperationalState::STATUS_MAX_LENGTH, enumType: HumanInputContinuationKindEnum::class)]
    public HumanInputContinuationKindEnum $continuationKind;

    #[ORM\Column(name: 'tool_call_id', type: 'string', length: RunOperationalState::ID_MAX_LENGTH, nullable: true)]
    #[Assert\Length(min: 1, max: RunOperationalState::ID_MAX_LENGTH)]
    public ?string $toolCallId = null;

    #[ORM\Column(type: 'string', length: RunOperationalState::STATUS_MAX_LENGTH)]
    #[Assert\Choice(choices: [self::STATUS_WAITING])]
    public string $status = self::STATUS_WAITING;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = Clock::get()->now();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }
}
