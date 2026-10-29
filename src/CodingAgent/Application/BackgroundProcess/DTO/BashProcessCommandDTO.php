<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess\DTO;

use Ineersa\CodingAgent\Application\BackgroundProcess\BashProcessActionEnum;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRequesterRoleEnum;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class BashProcessCommandDTO
{
    public function __construct(
        #[Assert\Positive]
        public int $recordId,
        #[Assert\NotBlank]
        public string $runId,
        public BashProcessActionEnum $action,
        #[Assert\Regex('/^[a-f0-9]{32}$/D')]
        public string $incarnation,
        #[Assert\Regex('/^[a-f0-9]{32}$/D')]
        public string $correlation,
        public BashRequesterRoleEnum $role,
    ) {
    }
}
