<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Validation\ViewImage;

use Symfony\Component\Validator\Constraint;

/**
 * Class-level constraint validating a ViewImageArgumentsDTO target before
 * the view_image tool executes.
 *
 * Covers active-model vision capability, path existence/regular/readability,
 * configured max bytes, magic-byte MIME support, and configured dimension
 * limits. The handler still reads stat/header/dimensions to produce its
 * metadata output and throws only on operational failures (races, I/O).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ViewImageTarget extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
