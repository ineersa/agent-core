<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Observer;

/**
 * Conservative OM token estimate from Unicode character count.
 */
final class OmTokenEstimator
{
    private const float CHARS_PER_TOKEN = 2.35;

    public static function estimate(string $text): int
    {
        return (int) ceil(mb_strlen($text, 'UTF-8') / self::CHARS_PER_TOKEN);
    }

    /** SQL equivalent for a trusted SQLite content expression, never user input. */
    public static function sqliteEstimate(string $contentExpression): string
    {
        // SQLite length counts Unicode characters but stops at NUL. Replacing NUL
        // with another single character preserves mb_strlen's character count.
        $quotient = 'length(replace('.$contentExpression.', char(0), char(1))) / '.self::CHARS_PER_TOKEN;

        return 'CAST(('.$quotient.') AS INTEGER) + (('.$quotient.') > CAST(('.$quotient.') AS INTEGER))';
    }

    public static function characterBudget(int $tokens): int
    {
        return (int) floor(max(0, $tokens) * self::CHARS_PER_TOKEN);
    }
}
