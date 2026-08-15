<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated arguments for the bash tool.
 *
 * Configured max timeout remains a runtime check in BashTool (depends on AppConfig).
 */
final class BashArgumentsDTO
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim', message: 'The "command" argument is required and must be a non-empty string.')]
        public readonly string $command = '',
        #[Assert\Positive(message: 'The "timeout" argument must be a positive integer.')]
        public readonly ?int $timeout = null,
    ) {
    }
}
