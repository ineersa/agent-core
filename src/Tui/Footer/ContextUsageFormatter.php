<?php

declare(strict_types=1);

namespace Ineersa\Tui\Footer;

use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\Tui\Listener\FooterStateInitializer;
use Ineersa\Tui\Listener\FooterStateSegmentProvider;
use Ineersa\Tui\Theme\ThemeColorEnum;

/**
 * Formats context-window usage like the main footer ctx segment: {@code 36% 97.9k/272.0k}.
 *
 * Uses latest-turn input tokens (not cumulative session input) when computing the percentage.
 */
final class ContextUsageFormatter
{
    public function __construct(
        private readonly ?AppConfig $appConfig = null,
    ) {
    }

    /**
     * @return array{text: string, color: ThemeColorEnum}|null
     */
    public function format(?string $model, int $latestInputTokens): ?array
    {
        if ($latestInputTokens <= 0) {
            return null;
        }

        $contextWindow = $this->resolveContextWindow($model);
        if ($contextWindow <= 0) {
            return null;
        }

        $pct = min(100.0, ($latestInputTokens / $contextWindow) * 100.0);
        $color = $pct > 75 ? ThemeColorEnum::Error : ($pct > 50 ? ThemeColorEnum::Warning : ThemeColorEnum::Success);
        $text = \sprintf(
            '%.0f%% %s/%s',
            $pct,
            FooterStateSegmentProvider::formatTokenCount($latestInputTokens),
            FooterStateSegmentProvider::formatTokenCount($contextWindow),
        );

        return ['text' => $text, 'color' => $color];
    }

    private function resolveContextWindow(?string $model): int
    {
        if (null === $this->appConfig || null === $model || '' === trim($model)) {
            return 0;
        }

        $ref = AiModelReference::tryParse($model);
        if (null === $ref) {
            return 0;
        }

        return FooterStateInitializer::resolveContextWindowForRef($this->appConfig, $ref);
    }
}
