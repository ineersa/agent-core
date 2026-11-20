<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CodeModeCallRequestDTO
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        #[Assert\Uuid] public string $invocationId,
        #[Assert\Regex('/^[a-f0-9]{16}$/D')] public string $callId,
        #[Assert\NotBlank, Assert\Length(max: 255)] public string $name,
        public array $arguments,
        #[Assert\Choice(choices: ['tool', 'notice'])] public string $operation = 'tool',
        #[Assert\Uuid] public ?string $rootId = null,
    ) {
    }
}
