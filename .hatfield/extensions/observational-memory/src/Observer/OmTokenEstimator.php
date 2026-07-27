<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Observer;

/**
 * Exact OM token estimator (task §C): ceil(mb_strlen UTF-8 / 4).
 */
final class OmTokenEstimator
{
    public static function estimate(string $text): int
    {
        $chars = max(0, mb_strlen($text, 'UTF-8'));

        return (int) ceil($chars / 4);
    }
}
