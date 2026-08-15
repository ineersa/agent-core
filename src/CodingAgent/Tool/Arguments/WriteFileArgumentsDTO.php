<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Arguments;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated arguments for the write tool.
 */
final class WriteFileArgumentsDTO
{
    public function __construct(
        #[Schema(description: 'File path to write (absolute, or relative to the working directory)')]
        #[Assert\NotBlank(normalizer: 'trim', message: 'The "path" argument is required and must be a non-empty string.')]
        public readonly string $path = '',
        #[Schema(description: 'Text content to write to the file')]
        #[Assert\NotNull(message: 'The "content" argument is required and must be a string.')]
        #[Assert\Type(type: 'string', message: 'The "content" argument is required and must be a string.')]
        public readonly ?string $content = null,
    ) {
    }
}
