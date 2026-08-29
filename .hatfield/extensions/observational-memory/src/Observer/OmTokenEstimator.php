<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Observer;

/**
 * Conservative OM token estimate from Unicode character count.
 */
final class OmTokenEstimator
{
    private const float CHARS_PER_TOKEN = 3.25;

    public static function estimate(string $text): int
    {
        return (int) ceil(mb_strlen($text, 'UTF-8') / self::CHARS_PER_TOKEN);
    }

    public static function characterBudget(int $tokens): int
    {
        return (int) floor(max(0, $tokens) * self::CHARS_PER_TOKEN);
    }
}
