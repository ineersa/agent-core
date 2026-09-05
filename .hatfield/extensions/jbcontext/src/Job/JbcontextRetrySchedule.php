<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Job;

/**
 * Fixed startup retry delays under a hard ~30s wall-clock budget.
 *
 * The budget covers sleeps and CLI status timeouts together. Attempts continue
 * only while remaining budget can cover the next status call.
 */
final class JbcontextRetrySchedule
{
    /** Total wall-clock budget for eligibility retries, including CLI time. */
    public const float BUDGET_SECONDS = 30.0;

    /** Conservative status CLI timeout charged against the budget. */
    public const float STATUS_TIMEOUT_SECONDS = 5.0;

    /** @var list<int> preferred seconds to wait before the next attempt */
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

    /**
     * Remaining wall-clock budget after elapsed time.
     */
    public static function remainingBudget(float $elapsedSeconds): float
    {
        return max(0.0, self::BUDGET_SECONDS - max(0.0, $elapsedSeconds));
    }

    /**
     * Whether another status attempt can still fit in the remaining budget.
     */
    public static function canAttemptStatus(float $elapsedSeconds): bool
    {
        return self::remainingBudget($elapsedSeconds) >= self::STATUS_TIMEOUT_SECONDS;
    }

    /**
     * Sleep before the next attempt, truncated so the next status call can still
     * fit. Null means the budget is exhausted.
     */
    public static function sleepBeforeNextAttempt(int $failedAttempt, float $elapsedSeconds): ?int
    {
        $preferred = self::delayAfterAttempt($failedAttempt);
        if (null === $preferred) {
            return null;
        }

        $remaining = self::remainingBudget($elapsedSeconds);
        $maxSleep = (int) floor($remaining - self::STATUS_TIMEOUT_SECONDS);
        if ($maxSleep < 0) {
            return null;
        }

        return min($preferred, $maxSleep);
    }
}
