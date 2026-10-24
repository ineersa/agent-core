<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Message\DTO;

use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionOriginEnum;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class DeliverBackgroundCompletionDTO extends AbstractAgentBusMessage
{
    public function __construct(
        string $runId,
        #[Assert\NotBlank, Assert\Length(max: 255)] public string $identity,
        public string $text,
        public BackgroundCompletionOriginEnum $origin,
        #[Assert\NotBlank(allowNull: true)] public ?string $sourceRunId = null,
        #[Assert\NotBlank(allowNull: true)] public ?string $deferredId = null,
        #[Assert\NotBlank(allowNull: true)] public ?string $sourceToolCallId = null,
        #[Assert\PositiveOrZero] public ?int $sourceTurnNo = null,
        #[Assert\NotBlank(allowNull: true)] public ?string $sourceStepId = null,
        #[Assert\PositiveOrZero] public ?int $sourceAttempt = null,
        #[Assert\NotBlank(allowNull: true)] public ?string $sourceIdempotencyKey = null,
        public bool $explicitContinuation = false,
    ) {
        parent::__construct($runId, 0, $identity, 1, $identity);
    }
}
