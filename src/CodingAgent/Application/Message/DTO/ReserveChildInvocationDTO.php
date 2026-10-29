<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Message\DTO;

use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchChildIntentDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRequesterRoleEnum;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ReserveChildInvocationDTO
{
    public function __construct(
        #[Assert\NotBlank] public string $lifecycleId,
        #[Assert\NotBlank] public string $parentRunId,
        #[Assert\PositiveOrZero] public int $parentTurnNo,
        #[Assert\NotBlank] public string $parentToolCallId,
        #[Assert\PositiveOrZero] public int $parentOrderIndex,
        public \DateTimeImmutable $deadlineAt,
        public DeferredSubagentBatchChildIntentDTO $childIntent,
        public ?string $parentModel,
        #[Assert\Regex('/^[a-f0-9]{32}$/D')] public string $incarnation,
        #[Assert\Regex('/^[a-f0-9]{32}$/D')] public string $correlation,
        public BashRequesterRoleEnum $role,
    ) {
    }
}
