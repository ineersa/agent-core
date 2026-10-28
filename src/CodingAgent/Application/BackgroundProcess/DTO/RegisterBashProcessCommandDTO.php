<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess\DTO;

use Ineersa\CodingAgent\Application\BackgroundProcess\BashRequesterRoleEnum;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class RegisterBashProcessCommandDTO
{
    public function __construct(
        #[Assert\Valid]
        public RegisterBashProcessDTO $registration,
        #[Assert\NotBlank]
        #[Assert\Regex('/^[a-f0-9]{32}$/D')]
        public string $incarnation,
        #[Assert\NotBlank]
        #[Assert\Regex('/^[a-f0-9]{32}$/D')]
        public string $correlation,
        public BashRequesterRoleEnum $role = BashRequesterRoleEnum::Tool,
    ) {
    }
}
