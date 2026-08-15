<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Arguments;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated arguments for the read tool.
 */
final class ReadFileArgumentsDTO
{
    public function __construct(
        #[Schema(description: 'File path to read (absolute, or relative to the working directory)')]
        #[Assert\NotBlank(normalizer: 'trim', message: 'The "path" argument is required and must be a non-empty string.')]
        public readonly string $path = '',
        #[Schema(description: 'Starting line number (1-indexed). Omit to read from the beginning.')]
        #[Assert\Positive(message: 'The "offset" argument must be a positive integer.')]
        public readonly ?int $offset = null,
        #[Schema(description: 'Maximum number of lines to return. Omit to use the default cap (2000 lines).')]
        #[Assert\Positive(message: 'The "limit" argument must be a positive integer.')]
        public readonly ?int $limit = null,
    ) {
    }
}
