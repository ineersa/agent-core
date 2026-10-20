<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/** JSON data for one invocation or outcome. No Messenger envelope or stamps. */
final readonly class ToolPayloadDataDTO
{
    /** @param array<string, mixed> $data */
    public function __construct(
        #[Assert\Choice(choices: ['call', 'result', 'shell', 'correlation'])]
        public string $kind,
        #[Assert\Count(min: 1)]
        public array $data,
    ) {
    }
}
