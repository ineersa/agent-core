<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundCompletion\DTO;

use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionDispositionEnum;
use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionOriginEnum;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class BackgroundCompletionReceiptDTO
{
    public function __construct(
        #[Assert\NotBlank] public string $identity,
        #[Assert\NotBlank] public string $sourceRunId,
        #[Assert\NotBlank] public string $acceptedDestinationRunId,
        #[Assert\NotBlank] public string $destinationRunId,
        #[Assert\NotBlank] public string $rootRunId,
        public string $text,
        public string $forwardedText,
        public BackgroundCompletionDispositionEnum $disposition,
        public BackgroundCompletionOriginEnum $origin,
        #[Assert\NotBlank(allowNull: true)] public ?string $deferredId = null,
        #[Assert\NotBlank(allowNull: true)] public ?string $sourceToolCallId = null,
        #[Assert\PositiveOrZero] public ?int $sourceTurnNo = null,
        #[Assert\NotBlank(allowNull: true)] public ?string $sourceStepId = null,
        #[Assert\PositiveOrZero] public ?int $sourceAttempt = null,
        #[Assert\NotBlank(allowNull: true)] public ?string $sourceIdempotencyKey = null,
    ) {
    }
}
