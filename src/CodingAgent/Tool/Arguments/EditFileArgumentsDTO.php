<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated arguments for the edit tool.
 */
final class EditFileArgumentsDTO
{
    public function __construct(
        #[Assert\NotBlank(message: 'The "path" argument is required and must be a non-empty string.')]
        public readonly string $path = '',
        #[Assert\NotBlank(message: 'The "patch" argument is required and must be a non-empty string.')]
        public readonly string $patch = '',
    ) {
    }
}
