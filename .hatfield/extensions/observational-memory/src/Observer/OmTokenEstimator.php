<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Observer;

/**
 * Small package-local token heuristic (Unicode codepoints / 3.25).
 *
 * Matches Hatfield compaction order-of-magnitude estimates without importing
 * CodingAgent internal classes.
 */
final class OmTokenEstimator
{
    public static function estimate(string $text): int
    {
        $chars = max(0, mb_strlen($text, 'UTF-8'));

        return (int) max(1, (int) ceil($chars / 3.25));
    }
}
