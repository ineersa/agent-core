<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class BashBackgroundRequestDTO
{
    public function __construct(
        #[Assert\Positive]
        public int $recordId = 0,
        #[Assert\NotBlank]
        public string $toolCallId = '',
    ) {
    }
}
