<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Arguments;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated arguments for the bg_status tool.
 *
 * pid is required (and positive) for the log and stop actions only — the
 * When constraints below encode that conditional; `list` ignores pid.
 */
final class BgStatusArgumentsDTO
{
    public function __construct(
        #[Schema(description: "Action: list session processes, log a process's output tail, or stop a process.")]
        #[Assert\NotBlank(message: 'The "action" argument is required and must be a non-empty string.')]
        #[Assert\Choice(choices: ['list', 'log', 'stop'], message: 'Invalid action "{{ value }}". Use one of: list, log, stop.')]
        public readonly string $action = '',
        #[Schema(description: 'Process PID (required for log and stop actions)')]
        #[Assert\Range(min: 1, minMessage: 'The "pid" argument must be a positive integer.')]
        #[Assert\When(
            expression: 'this.action === "log"',
            constraints: [
                new Assert\NotNull(message: 'The "pid" argument is required and must be a positive integer for the log action.'),
            ],
        )]
        #[Assert\When(
            expression: 'this.action === "stop"',
            constraints: [
                new Assert\NotNull(message: 'The "pid" argument is required and must be a positive integer for the stop action.'),
            ],
        )]
        public readonly ?int $pid = null,
    ) {
    }
}
