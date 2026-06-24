<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * One parallel subagent task entry ({agent, task}).
 */
final class SubagentTaskDTO
{
    public function __construct(
        #[Assert\NotBlank(message: 'Each task must include a non-empty "agent" string.')]
        public readonly string $agent = '',
        #[Assert\NotBlank(message: 'Each task must include a non-empty "task" string.')]
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
