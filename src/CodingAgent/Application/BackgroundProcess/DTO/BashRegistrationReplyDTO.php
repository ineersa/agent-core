<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess\DTO;

use Ineersa\CodingAgent\Application\BackgroundProcess\BashOwnershipStatusEnum;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class BashRegistrationReplyDTO
{
    public function __construct(
        #[Assert\NotBlank]
        public string $sessionId,
        #[Assert\NotBlank]
        #[Assert\Regex('/^[a-f0-9]{32}$/D')]
        public string $incarnation,
        #[Assert\NotBlank]
        #[Assert\Regex('/^[a-f0-9]{32}$/D')]
        public string $correlation,
        public ?BashRegistrationResultDTO $result = null,
        public ?BashOwnershipStatusEnum $ownership = null,
        public bool $failed = false,
    ) {
    }
}
