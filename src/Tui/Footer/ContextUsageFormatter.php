<?php

declare(strict_types=1);

namespace Ineersa\Tui\Footer;

use Ineersa\Tui\Theme\ThemeColorEnum;

/**
 * Pure formatter for context-window usage: {@code 36% 97.9k/272.0k}.
 *
 * Uses latest-turn input tokens (not cumulative) and an explicit context window.
 * Threshold colours match the main footer: >75 Error, >50 Warning, else Success.
 */
final class ContextUsageFormatter
{
    /**
     * @param non-empty-string|null $model required for visible child context output (no fabricated CTX without a model)
     */
    public static function format(?string $model, int $latestInputTokens, int $contextWindow): ?ContextUsageDTO
    {
        if (null === $model || '' === trim($model)) {
            return null;
        }
        if ($latestInputTokens <= 0 || $contextWindow <= 0) {
            return null;
        }

        $pct = min(100.0, ($latestInputTokens / $contextWindow) * 100.0);
        $color = $pct > 75 ? ThemeColorEnum::Error : ($pct > 50 ? ThemeColorEnum::Warning : ThemeColorEnum::Success);
        $text = \sprintf(
            '%.0f%% %s/%s',
            $pct,
            self::formatTokenCount($latestInputTokens),
            self::formatTokenCount($contextWindow),
        );

        return new ContextUsageDTO(text: $text, color: $color);
    }

    public static function formatTokenCount(int $n): string
    {
        if ($n >= 1_000) {
            return \sprintf('%.1fk', $n / 1_000);
        }

        return (string) $n;
    }
}
