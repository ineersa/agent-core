<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Validation\ReadFile;

use Symfony\Component\Validator\Constraint;

/**
 * Class-level constraint validating a ReadFileArgumentsDTO target before
 * the read tool executes.
 *
 * Covers safety blocks (device/fd paths), existence, regular-file and
 * readability preconditions, content inspection (MIME, binary null bytes,
 * UTF-8 validity, non-text extensions), and offset-past-EOF rejection.
 * The handler only resolves, reads, slices, and appends the continuation
 * hint; a file() failure there is an operational/race error.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ReadFileTarget extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
