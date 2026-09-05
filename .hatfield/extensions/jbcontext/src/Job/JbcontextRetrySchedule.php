<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Job;

/**
 * Fixed startup retry delays summing to ~30s of wait budget.
 *
 * Attempts: immediate, then +2s, +4s, +8s, +16s (5 attempts total).
 */
final class JbcontextRetrySchedule
{
    /** @var list<int> seconds to wait before the next attempt after a transient failure */
    public const array DELAYS_SECONDS = [2, 4, 8, 16];

    public static function maxAttempts(): int
    {
        return \count(self::DELAYS_SECONDS) + 1;
    }

    public static function delayAfterAttempt(int $attemptNumber): ?int
    {
        $index = $attemptNumber - 1;
        if (!isset(self::DELAYS_SECONDS[$index])) {
            return null;
        }

        return self::DELAYS_SECONDS[$index];
    }
}
