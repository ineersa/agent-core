<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated arguments for the view_image tool.
 */
final class ViewImageArgumentsDTO
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim', message: 'The "path" argument is required and must be a non-empty string.')]
        public readonly string $path = '',
    ) {
    }
}
