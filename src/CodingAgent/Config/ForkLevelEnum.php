<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Fork difficulty/autonomy level.
 *
 * Maps to the plan's fork guidance:
 * - Junior:  narrow, low-risk tasks (targeted one-shot operations)
 * - Middle:  general default, ~70% of session (balanced autonomy)
 * - Senior:  hard, high-risk tasks (maximal autonomy, complex reasoning)
 */
enum ForkLevelEnum: string
{
    case Junior = 'junior';
    case Middle = 'middle';
    case Senior = 'senior';

    /**
     * Parse a string value, returning null on unrecognised input.
     */
    public static function fromStringOrNull(?string $value): ?self
    {
        if (null === $value) {
            return null;
        }

        return self::tryFrom($value);
    }

    /**
     * The default fork level used when none is explicitly requested.
     */
    public static function default(): self
    {
        return self::Middle;
    }
}
