<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundCompletion\DTO;

use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionDispositionEnum;
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
    ) {
    }
}
