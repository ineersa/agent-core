<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Goal\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class GoalObjectiveDTO
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 4000, charset: 'UTF-8')]
        public string $objective,
    ) {
    }
}
