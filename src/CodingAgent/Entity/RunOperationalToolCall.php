<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ineersa\AgentCore\Domain\Run\RunOperationalToolCallStatusEnum;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Validator\Constraints as Assert;

/** Current tool-call coordination only; concrete payload remains in the execution payload store. */
#[ORM\Entity]
#[ORM\Table(name: 'run_operational_tool_call')]
#[ORM\Index(name: 'idx_run_operational_tool_current', columns: ['run_id', 'batch_id', 'status', 'order_index'])]
#[ORM\HasLifecycleCallbacks]
final class RunOperationalToolCall
{
    use TimestampableLifecycleTrait;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: RunOperationalState::class, inversedBy: 'toolCalls')]
    #[ORM\JoinColumn(name: 'run_id', referencedColumnName: 'run_id', nullable: false, onDelete: 'CASCADE')]
    public RunOperationalState $run;

    #[ORM\Id]
    #[ORM\Column(name: 'batch_id', type: 'string', length: RunOperationalState::ID_MAX_LENGTH)]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: RunOperationalState::ID_MAX_LENGTH)]
    public string $batchId;

    #[ORM\Id]
    #[ORM\Column(name: 'tool_call_id', type: 'string', length: RunOperationalState::ID_MAX_LENGTH)]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: RunOperationalState::ID_MAX_LENGTH)]
    public string $toolCallId;

    #[ORM\Column(name: 'order_index', type: 'integer')]
    #[Assert\GreaterThanOrEqual(0)]
    public int $orderIndex;

    #[ORM\Column(type: 'string', length: RunOperationalState::STATUS_MAX_LENGTH, enumType: RunOperationalToolCallStatusEnum::class)]
    public RunOperationalToolCallStatusEnum $status;

    #[ORM\Column(type: 'integer')]
    #[Assert\GreaterThanOrEqual(0)]
    public int $attempt;

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
