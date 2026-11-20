<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Message\DTO;

use Ineersa\AgentCore\Contract\Tool\DeferredToolInterruptionReasonEnum;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class MarkCodeModeBackgroundedDTO
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $deferredId,
        public \DateTimeImmutable $acceptedAt,
        public ?DeferredToolInterruptionReasonEnum $interruptionReason = null,
        public ?bool $continuationRequired = null,
    ) {
    }
}
