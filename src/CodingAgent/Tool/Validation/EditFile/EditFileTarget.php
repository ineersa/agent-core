<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Validation\EditFile;

use Symfony\Component\Validator\Constraint;

/**
 * Class-level constraint validating an EditFileArgumentsDTO target before
 * the edit tool executes.
 *
 * Covers the target exists/regular/readable precondition only. Patch
 * applicability (stale/ambiguous hunks, write failures) is execution-time
 * state under the applier's lock and stays in PatchApplier.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class EditFileTarget extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
