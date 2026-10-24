<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * Requires every list entry to be a non-empty absolute filesystem path.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class AbsolutePathList extends Constraint
{
    public string $message = 'allow_write_outside_cwd entries must be absolute paths; got "{{ value }}".';
}
