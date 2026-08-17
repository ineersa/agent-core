<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Arguments;

use Ineersa\CodingAgent\Tool\Validation\ViewImage\ViewImageTarget;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated arguments for the view_image tool.
 *
 * Policy preconditions (vision capability, target existence/readability,
 * max bytes, supported MIME, dimension limits) are enforced by the
 * class-level {@see ViewImageTarget} constraint before execution.
 */
#[ViewImageTarget]
final class ViewImageArgumentsDTO
{
    public function __construct(
        #[Schema(description: 'Path to the image file (absolute, or relative to the working directory)')]
        #[Assert\NotBlank(normalizer: 'trim', message: 'The "path" argument is required and must be a non-empty string.')]
        public readonly string $path = '',
    ) {
    }
}
