<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\CodeMode\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CodeModeBackgroundRequestDTO
{
    public function __construct(
        #[Assert\Uuid] public string $identity,
        #[Assert\NotBlank] public string $toolCallId,
    ) {
    }
}
