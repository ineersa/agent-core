<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Deferred\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class MarkDeferredAcknowledgedDTO
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $deferredId,
    ) {
    }
}
