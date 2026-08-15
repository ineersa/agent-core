<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated arguments for the read tool.
 */
final class ReadFileArgumentsDTO
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim', message: 'The "path" argument is required and must be a non-empty string.')]
        public readonly string $path = '',
        #[Assert\Positive(message: 'The "offset" argument must be a positive integer.')]
        public readonly ?int $offset = null,
        #[Assert\Positive(message: 'The "limit" argument must be a positive integer.')]
        public readonly ?int $limit = null,
    ) {
    }
}
