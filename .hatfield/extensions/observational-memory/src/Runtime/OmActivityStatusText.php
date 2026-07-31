<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Runtime;

/**
 * Pi-style live status copy for the TUI status row (key om-background).
 */
final class OmActivityStatusText
{
    public static function format(string $stage, int $currentTokens, ?int $targetTokens = null): string
    {
        $current = number_format($currentTokens);

        return match ($stage) {
            'observer' => \sprintf('Observational memory: observer running on ~%s-token chunk', $current),
            'reflector' => \sprintf('Observational memory: reflector running (~%s tokens)', $current),
            'dropper' => self::formatDropper($currentTokens, $targetTokens),
            default => throw new \InvalidArgumentException(\sprintf('Unknown OM activity stage "%s".', $stage)),
        };
    }

    private static function formatDropper(int $currentTokens, ?int $targetTokens): string
    {
        $target = $targetTokens ?? 0;
        $pct = $target > 0 ? (int) round(100 * $currentTokens / $target) : 0;

        return \sprintf(
            'Observational memory: dropper running — active observation pool ~%s / %s target tokens (%d%%)',
            number_format($currentTokens),
            number_format($target),
            $pct,
        );
    }
}
