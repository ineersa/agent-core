<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One parallel subagent task entry ({agent, task}).
 */
final class SubagentTaskDTO
{
    public function __construct(
        #[Schema(description: 'Agent definition name.')]
        #[Assert\NotBlank(normalizer: 'trim', message: 'Each task must include a non-empty "agent" string.')]
        public readonly string $agent = '',
        #[Schema(description: 'Task text.')]
        #[Assert\NotBlank(normalizer: 'trim', message: 'Each task must include a non-empty "task" string.')]
        public readonly string $task = '',
    ) {
    }

    public function trimmedAgent(): string
    {
        return trim($this->agent);
    }

    public function trimmedTask(): string
    {
        return trim($this->task);
    }
}
