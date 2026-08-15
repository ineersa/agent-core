<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated arguments for the write tool.
 */
final class WriteFileArgumentsDTO
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim', message: 'The "path" argument is required and must be a non-empty string.')]
        public readonly string $path = '',
        #[Assert\NotNull(message: 'The "content" argument is required and must be a string.')]
        #[Assert\Type(type: 'string', message: 'The "content" argument is required and must be a string.')]
        public readonly ?string $content = null,
    ) {
    }
}
