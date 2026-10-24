<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * Strict open unit interval used by OmSettings::readRatio (0 < ratio < 1).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class OpenUnitRatio extends Constraint
{
    public string $message = 'context_window_ratio must satisfy 0 < ratio < 1.';
}
