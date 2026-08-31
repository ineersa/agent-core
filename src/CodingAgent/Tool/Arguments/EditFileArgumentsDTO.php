<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Arguments;

use Ineersa\CodingAgent\Tool\Validation\EditFile\EditFileTarget;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated arguments for the edit tool.
 *
 * The target exists/regular/readable precondition is enforced by the
 * class-level {@see EditFileTarget} constraint before execution; patch
 * applicability stays execution-time under the applier's lock.
 */
#[EditFileTarget]
final class EditFileArgumentsDTO
{
    public function __construct(
        #[Schema(description: 'File path to edit (absolute, or relative to the working directory)')]
        #[Assert\NotBlank(normalizer: 'trim', message: 'The "path" argument is required and must be a non-empty string.')]
        public readonly string $path = '',
        #[Schema(description: 'Hunk body beginning with `@@`; prefix each body line with a space for unchanged context, `-` for removal, or `+` for addition. Multiple sequential, non-overlapping hunks are allowed. End after the final hunk; do not append `*** End Patch`.')]
        #[Assert\NotBlank(normalizer: 'trim', message: 'The "patch" argument is required and must be a non-empty string.')]
        public readonly string $patch = '',
    ) {
    }
}
