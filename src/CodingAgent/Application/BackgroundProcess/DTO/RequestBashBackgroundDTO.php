<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RequestBashBackgroundDTO
{
    public function __construct(
        #[Assert\NotBlank]
        public string $runId,
        #[Assert\Positive]
        public int $recordId,
        #[Assert\NotBlank]
        public string $toolCallId,
    ) {
    }
}
