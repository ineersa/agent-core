<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class StopBashRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        public string $runId,
        #[Assert\Positive]
        public int $recordId,
    ) {
    }
}
