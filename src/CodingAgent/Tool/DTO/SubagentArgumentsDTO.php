<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\DTO;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SubagentArgumentsDTO
{
    public function __construct(
        #[Schema(description: 'Agent definition name.')]
        #[Assert\NotBlank(normalizer: 'trim', message: 'Subagent requires a non-empty "agent" string.')]
        public string $agent,
        #[Schema(description: 'Task text.')]
        #[Assert\NotBlank(normalizer: 'trim', message: 'Subagent requires a non-empty "task" string.')]
        public string $task,
        #[Schema(description: 'Request background launch after startup; returns an acknowledgement and delivers the result later. Default false.')]
        public bool $backgrounded = false,
    ) {
    }
}
