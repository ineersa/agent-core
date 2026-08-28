<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Ineersa\AgentCore\Domain\Run\CurrentOperationDTO;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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

    #[ORM\Column(name: 'operation_turn_no', type: 'integer', nullable: true)]
    #[Assert\GreaterThanOrEqual(0)]
    public ?int $operationTurnNo;

    #[ORM\Column(name: 'operation_step_id', type: 'string', length: self::ID_MAX_LENGTH, nullable: true)]
    #[Assert\Length(min: 1, max: self::ID_MAX_LENGTH)]
    public ?string $operationStepId;

    #[ORM\Column(name: 'operation_attempt', type: 'integer', nullable: true)]
    #[Assert\GreaterThanOrEqual(1)]
    public ?int $operationAttempt;

    #[ORM\Column(name: 'operation_key', type: 'string', length: self::ID_MAX_LENGTH, nullable: true)]
    #[Assert\Length(min: 1, max: self::ID_MAX_LENGTH)]
    public ?string $operationKey;

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
    private Collection $toolCalls;

    /** @var Collection<int, RunOperationalHumanInput> */
    #[ORM\OneToMany(targetEntity: RunOperationalHumanInput::class, mappedBy: 'run', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Assert\Valid]
    private Collection $humanInputs;

    public function __construct(
        string $runId,
        string $ownerSessionId,
        RunStatus $status,
        int $turnNo,
        ?string $activeStepId,
        ?CurrentOperationDTO $currentOperation,
        ?string $lastAppliedAdvanceKey,
        ?string $lastAppliedCompactionKey,
        bool $retryableFailure,
        int $retryAttempts,
        int $lastEventSequence,
        int $transitionVersion,
    ) {
        $this->toolCalls = new ArrayCollection();
        $this->humanInputs = new ArrayCollection();
        $now = Clock::get()->now();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->runId = $runId;
        $this->replaceScalars(
            $ownerSessionId,
            $status,
            $turnNo,
            $activeStepId,
            $currentOperation,
            $lastAppliedAdvanceKey,
            $lastAppliedCompactionKey,
            $retryableFailure,
            $retryAttempts,
            $lastEventSequence,
            $transitionVersion,
        );
    }

    public function currentOperation(): ?CurrentOperationDTO
    {
        if (null === $this->operationKey) {
            return null;
        }

        if (null === $this->operationTurnNo || null === $this->operationStepId || null === $this->operationAttempt) {
            throw new \UnexpectedValueException('Persisted current operation is incomplete.');
        }

        return new CurrentOperationDTO(
            $this->operationTurnNo,
            $this->operationStepId,
            $this->operationAttempt,
            $this->operationKey,
        );
    }

    public function replaceFrom(self $replacement): void
    {
        if ($this->runId !== $replacement->runId) {
            throw new \LogicException('An operational projection cannot change its run identity.');
        }

        $this->replaceScalars(
            $replacement->ownerSessionId,
            $replacement->status,
            $replacement->turnNo,
            $replacement->activeStepId,
            $replacement->currentOperation(),
            $replacement->lastAppliedAdvanceKey,
            $replacement->lastAppliedCompactionKey,
            $replacement->retryableFailure,
            $replacement->retryAttempts,
            $replacement->lastEventSequence,
            $replacement->transitionVersion,
        );

        $this->synchronizeToolCalls($replacement->toolCalls);
        $this->synchronizeHumanInputs($replacement->humanInputs);
    }

    public function addToolCall(RunOperationalToolCall $toolCall): void
    {
        $this->toolCalls->add($toolCall);
    }

    public function addHumanInput(RunOperationalHumanInput $humanInput): void
    {
        $this->humanInputs->add($humanInput);
    }

    #[Assert\Callback]
    public function validateOperation(ExecutionContextInterface $context): void
    {
        $values = [$this->operationTurnNo, $this->operationStepId, $this->operationAttempt, $this->operationKey];
        $present = \count(array_filter($values, static fn (mixed $value): bool => null !== $value));
        if (0 !== $present && 4 !== $present) {
            $context->buildViolation('Current operation fields must be all present or all absent.')->addViolation();
        }
    }

    private function replaceScalars(
        string $ownerSessionId,
        RunStatus $status,
        int $turnNo,
        ?string $activeStepId,
        ?CurrentOperationDTO $currentOperation,
        ?string $lastAppliedAdvanceKey,
        ?string $lastAppliedCompactionKey,
        bool $retryableFailure,
        int $retryAttempts,
        int $lastEventSequence,
        int $transitionVersion,
    ): void {
        $this->ownerSessionId = $ownerSessionId;
        $this->status = $status;
        $this->turnNo = $turnNo;
        $this->activeStepId = $activeStepId;
        $this->operationTurnNo = $currentOperation?->turnNo;
        $this->operationStepId = $currentOperation?->stepId;
        $this->operationAttempt = $currentOperation?->attempt;
        $this->operationKey = $currentOperation?->idempotencyKey;
        $this->lastAppliedAdvanceKey = $lastAppliedAdvanceKey;
        $this->lastAppliedCompactionKey = $lastAppliedCompactionKey;
        $this->retryableFailure = $retryableFailure;
        $this->retryAttempts = $retryAttempts;
        $this->lastEventSequence = $lastEventSequence;
        $this->transitionVersion = $transitionVersion;
    }

    /** @param Collection<int, RunOperationalToolCall> $desired */
    private function synchronizeToolCalls(Collection $desired): void
    {
        $existing = [];
        foreach ($this->toolCalls as $toolCall) {
            $existing[$toolCall->identity()] = $toolCall;
        }

        foreach ($desired as $toolCall) {
            $identity = $toolCall->identity();
            if (isset($existing[$identity])) {
                $existing[$identity]->replaceFrom($toolCall);
                unset($existing[$identity]);
                continue;
            }

            $this->toolCalls->add($toolCall->forRun($this));
        }

        foreach ($existing as $toolCall) {
            $this->toolCalls->removeElement($toolCall);
        }
    }

    /** @param Collection<int, RunOperationalHumanInput> $desired */
    private function synchronizeHumanInputs(Collection $desired): void
    {
        $existing = [];
        foreach ($this->humanInputs as $humanInput) {
            $existing[$humanInput->questionId] = $humanInput;
        }

        foreach ($desired as $humanInput) {
            if (isset($existing[$humanInput->questionId])) {
                $existing[$humanInput->questionId]->replaceFrom($humanInput);
                unset($existing[$humanInput->questionId]);
                continue;
            }

            $this->humanInputs->add($humanInput->forRun($this));
        }

        foreach ($existing as $humanInput) {
            $this->humanInputs->removeElement($humanInput);
        }
    }
}
