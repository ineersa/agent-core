<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundCompletion\DTO;

use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionDispositionEnum;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ConsumeBackgroundCompletionDTO
{
    public function __construct(
        #[Assert\NotBlank] public string $identity,
        #[Assert\NotBlank, SerializedName('source_run_id')] public string $sourceRunId,
        #[Assert\NotBlank, SerializedName('destination_run_id')] public string $destinationRunId,
        #[Assert\Choice(choices: [BackgroundCompletionDispositionEnum::Consumed, BackgroundCompletionDispositionEnum::Forwarding])]
        public BackgroundCompletionDispositionEnum $disposition,
    ) {
    }
}
