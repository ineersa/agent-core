<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundCompletion\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class BackgroundCompletionProvenanceDTO
{
    public function __construct(
        #[Assert\NotBlank] public string $identity,
        #[Assert\NotBlank] public string $sourceRunId,
    ) {
    }
}
