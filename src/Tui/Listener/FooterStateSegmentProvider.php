<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Footer\ContextUsageFormatter;
use Ineersa\Tui\Footer\FooterSegment;
use Ineersa\Tui\Footer\FooterSegmentProvider;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Theme\ThemeColorEnum;

/**
 * Footer segment provider that reads live TuiSessionState and produces
 * formatted segments matching the Pi reference footer composition.
 *
 * Segment order:
 *   ◆ model (priority 0-1) — both coloured by reasoning level
 *     token block: input/output(10)  $cost(11)  ↻cache%(12, opt)  pct% used/ctx(13)
 *     ⚡ t/s (15, optional)
 *     ⏱ elapsed (20)
 *     ⌂ cwd (25)
 *     ⎇ branch (30)
 *
 * Reasoning level is NOT shown as a text segment — it only affects the
 * diamond AND model-name colour (matching Pi's thinking-level colouring).
 */
final readonly class FooterStateSegmentProvider implements FooterSegmentProvider
{
    public function __construct(
        private TuiSessionState $state,
    ) {
    }

    /** @return list<FooterSegment> */
    public function getSegments(): array
    {
        $s = $this->state;
        if ($s->subagentLiveView->active) {
            return $this->liveViewSegments($s);
        }

        $segments = [];

        // ── Group 1: ◆ model (priorities 0-1) ──
        // Both diamond and model name reflect the current reasoning level.
        $modelName = '' !== $s->footerModel ? $s->footerModel : 'no-model';
        $thinkColor = self::thinkingColor($s->footerReasoning);

        $segments[] = new FooterSegment(
            text: '◆',
            priority: 0,
            color: $thinkColor,
        );
        $segments[] = new FooterSegment(
            text: $modelName,
            priority: 1,
            color: $thinkColor,
        );

        // ── Group 2: Token stats block (priorities 10-13) ──
        $in = self::fmt($s->usage->inputTokens);
        $out = self::fmt($s->usage->outputTokens);
        $segments[] = new FooterSegment(
            text: \sprintf('%s/%s', $in, $out),
            priority: 10,
            color: ThemeColorEnum::Accent,
        );

        $segments[] = new FooterSegment(
            text: \sprintf('$%.2f', $s->usage->totalCost),
            priority: 11,
            color: ThemeColorEnum::Warning,
        );

        // Cache-hit segment: only shown when cache-read telemetry exists.
        // Absent telemetry is not shown (no misleading 0%).
        $cachePct = $s->usage->cacheReadHitPercentage();
        if (null !== $cachePct) {
            $segments[] = new FooterSegment(
                text: \sprintf('↻ %.0f%%', $cachePct),
                priority: 12,
                color: ThemeColorEnum::Success,
            );
        }

        $ctxFormatted = ContextUsageFormatter::format(
            '' !== $s->footerModel ? $s->footerModel : null,
            $s->usage->latestInputTokens,
            $s->contextWindow,
        );
        if (null !== $ctxFormatted) {
            $ctxDetail = $ctxFormatted->text;
            $pctColor = $ctxFormatted->color;
        } else {
            $ctxDetail = '0%';
            $pctColor = ThemeColorEnum::Success;
        }
        $segments[] = new FooterSegment(
            text: $ctxDetail,
            priority: 13,
            color: $pctColor,
        );

        // ── Group 3: Throughput (priority 15, optional) ──
        // Only show t/s when we have output tokens in the current turn
        // AND LLM has started streaming. Uses per-turn timing
        // (turnStartTime/llmEndTime) so the figure reflects only the
        // current turn's throughput and freezes once the response completes.
        if ($s->usage->turnOutputTokens > 0 && $s->usage->turnStartTime > 0) {
            $endTime = $s->usage->llmEndTime > 0.0 ? $s->usage->llmEndTime : microtime(true);
            $elapsed = $endTime - $s->usage->turnStartTime;
            if ($elapsed > 0) {
                $tps = $s->usage->turnOutputTokens / $elapsed;
                $segments[] = new FooterSegment(
                    text: \sprintf('⚡ %.1f t/s', $tps),
                    priority: 15,
                    color: ThemeColorEnum::Success,
                );
            }
        }

        // ── Group 4: Elapsed time (priority 20) ──
        $elapsed = microtime(true) - $s->sessionStartTime;
        $segments[] = new FooterSegment(
            text: \sprintf('⏱ %s', self::formatElapsed($elapsed)),
            priority: 20,
            color: ThemeColorEnum::Dim,
        );

        // ── Group 5: CWD (priority 25) ──
        if ('' !== $s->cwd) {
            $segments[] = new FooterSegment(
                text: \sprintf('⌂ %s', $s->cwd),
                priority: 25,
                color: ThemeColorEnum::Muted,
            );
        }

        // ── Group 6: Branch (priority 30) ──
        if ('' !== $s->branch) {
            $segments[] = new FooterSegment(
                text: \sprintf('⎇ %s', $s->branch),
                priority: 30,
                color: ThemeColorEnum::Accent,
            );
        }

        return $segments;
    }

    /**
     * Stable fingerprint of rendered footer segments for tick coalescing.
     *
     * Includes text, priority, and color so elapsed/tps/token updates trigger
     * a repaint without re-rendering on every Revolt tick when unchanged.
     */
    public function footerFingerprint(): string
    {
        $parts = [];
        foreach ($this->getSegments() as $segment) {
            $parts[] = \sprintf(
                '%d:%s:%s',
                $segment->priority,
                $segment->text,
                $segment->color->value,
            );
        }

        return implode("\n", $parts);
    }

    // ── Formatting helpers ──

    /**
     * Map a reasoning level to the dedicated ThemeColorEnum thinking token.
     *
     * Uses the semantic ThemeColorEnum::Thinking* tokens (not generic
     * Accent/Warning/Dim) for consistent reasoning-level colouring
     * across the diamond, model name, and any future thinking indicators.
     *
     * Public so callers that need the same mapping (e.g. editor border colour)
     * can reuse it without duplicating the logic.
     */
    public static function thinkingColor(string $reasoning): ThemeColorEnum
    {
        return ThemeColorEnum::forReasoning($reasoning);
    }

    public static function formatTokenCount(int $n): string
    {
        return ContextUsageFormatter::formatTokenCount($n);
    }

    /** @return list<FooterSegment> */
    private function liveViewSegments(TuiSessionState $s): array
    {
        $segments = [];
        $segments[] = new FooterSegment(text: '◆', priority: 0, color: ThemeColorEnum::Accent);
        $segments[] = new FooterSegment(text: 'agents-live', priority: 1, color: ThemeColorEnum::Accent);

        $child = $s->subagentLiveView->selected;
        if (null !== $child) {
            $statusLabel = $child->statusLabel();
            $agentStatusText = \in_array($child->status->value, ['running', 'waiting_human'], true)
                ? \sprintf('%s [%s]', $child->agentName, $statusLabel)
                : \sprintf('%s · %s', $child->agentName, $statusLabel);
            $segments[] = new FooterSegment(
                text: $agentStatusText,
                priority: 5,
                color: ThemeColorEnum::Working,
            );
            $ctxFormatted = ContextUsageFormatter::format($child->model, $child->latestInputTokens, $child->contextWindow);
            if (null !== $ctxFormatted) {
                $segments[] = new FooterSegment(text: $ctxFormatted->text, priority: 6, color: $ctxFormatted->color);
            }
            if (null !== $child->model && '' !== $child->model) {
                $segments[] = new FooterSegment(
                    text: FooterStateInitializer::shortModelName($child->model),
                    priority: 7,
                    color: ThemeColorEnum::Accent,
                );
            }
        }

        $segments[] = new FooterSegment(text: '/agents-main', priority: 10, color: ThemeColorEnum::Dim);
        $segments[] = new FooterSegment(text: 'Ctrl+\\ main', priority: 11, color: ThemeColorEnum::Dim);
        $segments[] = new FooterSegment(text: 'Esc cancel child', priority: 12, color: ThemeColorEnum::Muted);

        return $segments;
    }

    private static function fmt(int $n): string
    {
        return self::formatTokenCount($n);
    }

    private static function formatElapsed(float $elapsedSeconds): string
    {
        $total = (int) $elapsedSeconds;

        if ($total < 60) {
            return \sprintf('%ds', $total);
        }

        $minutes = (int) ($total / 60);
        $seconds = $total % 60;

        if ($minutes < 60) {
            return $seconds > 0 ? \sprintf('%dm%ds', $minutes, $seconds) : \sprintf('%dm', $minutes);
        }

        $hours = (int) ($minutes / 60);
        $remMinutes = $minutes % 60;

        return $remMinutes > 0 ? \sprintf('%dh%dm', $hours, $remMinutes) : \sprintf('%dh', $hours);
    }
}
