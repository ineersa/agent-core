<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\CodeMode\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class MarkCodeModeDTO
{
    public function __construct(#[Assert\NotBlank] public string $identity)
    {
    }
}
