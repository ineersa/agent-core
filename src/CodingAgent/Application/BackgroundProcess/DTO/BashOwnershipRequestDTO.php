<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class BashOwnershipRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Regex('/^[a-f0-9]{32}$/D')]
        public string $identity,
        #[Assert\NotBlank]
        #[Assert\Regex('/^[1-9][0-9]*$/D')]
        public string $ownerSessionId,
    ) {
    }
}
