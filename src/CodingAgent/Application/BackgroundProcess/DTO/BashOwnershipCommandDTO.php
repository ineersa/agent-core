<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess\DTO;

use Ineersa\CodingAgent\Application\BackgroundProcess\BashOwnershipActionEnum;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class BashOwnershipCommandDTO
{
    public function __construct(
        #[Assert\Valid]
        public BashOwnershipRequestDTO $request,
        public BashOwnershipActionEnum $action,
        #[Assert\Regex('/^[a-f0-9]{32}$/D')]
        public string $incarnation,
        #[Assert\Regex('/^[a-f0-9]{32}$/D')]
        public string $correlation,
    ) {
    }
}
