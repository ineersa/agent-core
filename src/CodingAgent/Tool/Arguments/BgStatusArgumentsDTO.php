<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Arguments;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated arguments for the bg_status tool.
 *
 * pid required-for log/stop remains a runtime check (conditional on action).
 */
final class BgStatusArgumentsDTO
{
    public function __construct(
        #[Schema(description: "Action: list session processes, log a process's output tail, or stop a process.")]
        #[Assert\NotBlank(message: 'The "action" argument is required and must be a non-empty string.')]
        #[Assert\Choice(choices: ['list', 'log', 'stop'], message: 'Invalid action "{{ value }}". Use one of: list, log, stop.')]
        public readonly string $action = '',
        #[Schema(description: 'Process PID (required for log and stop actions)')]
        #[Assert\Positive(message: 'The "pid" argument must be a positive integer.')]
        public readonly ?int $pid = null,
    ) {
    }
}
