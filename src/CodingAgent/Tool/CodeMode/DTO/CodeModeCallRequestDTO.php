<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CodeModeCallRequestDTO
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        #[Assert\NotBlank, Assert\Length(max: 255)] public string $invocationId,
        #[Assert\Regex('/^[a-f0-9]{16}$/D')] public string $callId,
        #[Assert\NotBlank, Assert\Length(max: 255)] public string $name,
        public array $arguments,
        #[Assert\Choice(choices: ['tool', 'notice', 'admit'])] public string $operation = 'tool',
        #[Assert\NotBlank, Assert\Length(max: 255)] public ?string $rootId = null,
        public ?string $runId = null,
    ) {
    }
}
