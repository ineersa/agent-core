<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess\DTO;

use Ineersa\CodingAgent\Application\BackgroundProcess\BashOwnershipStatusEnum;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRequesterRoleEnum;
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
        public BashRequesterRoleEnum $role = BashRequesterRoleEnum::Tool,
        public ?BashProcessSnapshotDTO $process = null,
        #[Assert\Length(min: 1, max: 128)]
        public ?string $deferredId = null,
    ) {
    }
}
