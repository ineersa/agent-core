<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Footer\FooterSegment;
use Ineersa\Tui\Footer\FooterSegmentProvider;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Theme\ThemeColor;
use Symfony\Component\Tui\Event\TickEvent;

/**
 * Listener registrar that initialises and updates the TUI footer state.
 *
 * On registration (run start / resume), it:
 *  - Seeds model/reasoning from session metadata
 *  - Detects cwd and git branch
 *  - Records the session start time
 *  - Registers a FooterSegmentProvider on the footer data provider
 *
 * On each tick, token usage is accumulated into TuiSessionState by
 * RuntimeEventPoller::extractFooterUsage(). A tick handler refreshes
 * the screen so the footer re-renders with updated elapsed time and
 * throughput.
 */
final readonly class FooterStateListener implements TuiListenerRegistrar
{
    public function __construct(
        private HatfieldSessionStore $sessionStore,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $state = $context->state;

        // Seed model/reasoning from session metadata
        $meta = $this->sessionStore->loadMetadata($state->sessionId);
        if (null !== $meta) {
            $v = $meta['model'] ?? '';
            $state->footerModel = \is_string($v) ? $v : '';
            $v = $meta['reasoning'] ?? '';
            $state->footerReasoning = \is_string($v) ? $v : '';
        }

        // Also check the StartRunRequest for model/reasoning (used on first run)
        if ('' === $state->footerModel && null !== $state->request) {
            $state->footerModel = (string) ($state->request->model ?? '');
            $state->footerReasoning = (string) ($state->request->reasoning ?? '');
        }

        // Short model display: strip common provider prefix from visible model name
        $state->footerModel = self::shortModelName($state->footerModel);

        // Set session start time
        if (0.0 === $state->sessionStartTime) {
            $state->sessionStartTime = microtime(true);
        }

        // Detect cwd (abbreviate home to ~)
        $cwd = getcwd();
        $state->cwd = false !== $cwd ? self::abbreviatePath($cwd) : '';

        // Detect git branch (robust: fail silently)
        $state->branch = self::detectGitBranch();

        // Register an anonymous FooterSegmentProvider that reads live state
        $context->screen->addFooterProvider(self::createStateProvider($state));

        // Refresh footer display on every tick so duration, throughput, etc. stay live
        $screen = $context->screen;
        $context->tui->onTick(static function (TickEvent $event) use ($screen): ?bool {
            $screen->refresh();

            return null;
        });
    }

    public static function detectGitBranch(): string
    {
        $descriptors = [
            ['pipe', 'r'],
            ['pipe', 'w'],
            ['pipe', 'w'],
        ];

        $process = @proc_open(
            ['git', 'rev-parse', '--abbrev-ref', 'HEAD'],
            $descriptors,
            $pipes,
        );

        if (!\is_resource($process)) {
            return '';
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if (0 !== $exitCode) {
            return '';
        }

        $branch = trim((string) $stdout);

        return '' !== $branch ? $branch : '';
    }

    // ── Provider factory ──

    /**
     * Create a FooterSegmentProvider that reads from TuiSessionState at render time.
     *
     * Segment priorities are carefully spaced so the FooterBarWidget groups
     * related items together and inserts "  |  " separators between groups:
     *
     *   ◆ model | reasoning | 0/0 $0.00 0% 0/0 | ⚡ 0.0 t/s | ⏱ 0s | ⌂ cwd | ⎇ branch
     *
     * Within each group (token stats), priorities differ by < 5 so they render
     * space-separated. Between groups, priorities differ by >= 5 for pipe separators.
     */
    private static function createStateProvider(TuiSessionState $state): FooterSegmentProvider
    {
        return new readonly class($state) implements FooterSegmentProvider {
            public function __construct(
                private TuiSessionState $state,
            ) {
            }

            /** @return list<FooterSegment> */
            public function getSegments(): array
            {
                $s = $this->state;
                $segments = [];

                // ── Model (priority 0) ──
                $modelName = '' !== $s->footerModel ? $s->footerModel : 'no-model';
                // Thinking-level indicator colour based on reasoning level
                $thinkColor = self::thinkingColor($s->footerReasoning);
                $segments[] = new FooterSegment(
                    text: \sprintf('◆ %s', $modelName),
                    priority: 0,
                    color: $thinkColor,
                );

                // ── Reasoning level (priority 5) ──
                if ('' !== $s->footerReasoning) {
                    $segments[] = new FooterSegment(
                        text: $s->footerReasoning,
                        priority: 5,
                        color: ThemeColor::Muted,
                    );
                }

                // ── Token stats block (priorities 10-14) ──
                // Small priority gaps (< 5) keep these space-separated
                $in = self::abbreviateNumber($s->inputTokens);
                $out = self::abbreviateNumber($s->outputTokens);
                $segments[] = new FooterSegment(
                    text: \sprintf('%s/%s', $in, $out),
                    priority: 10,
                    color: ThemeColor::Accent,
                );

                // Cost estimate
                $costStr = $s->totalCost > 0 ? \sprintf('$%.2f', $s->totalCost) : '$--';
                $segments[] = new FooterSegment(
                    text: $costStr,
                    priority: 11,
                    color: ThemeColor::Warning,
                );

                // Context window usage percentage
                if ($s->contextWindow > 0 && $s->inputTokens > 0) {
                    $pct = min(100, ($s->inputTokens / $s->contextWindow) * 100);
                    $conn = \sprintf('%.0f%%', $pct);
                    $pctColor = $pct > 75 ? ThemeColor::Error : ($pct > 50 ? ThemeColor::Warning : ThemeColor::Success);
                    $segments[] = new FooterSegment(text: $conn, priority: 12, color: $pctColor);
                } else {
                    $segments[] = new FooterSegment(text: '--%', priority: 12, color: ThemeColor::Muted);
                }

                // Context window detail: current / total
                $ctxDetail = $s->contextWindow > 0
                    ? \sprintf('%s/%s', self::abbreviateNumber($s->inputTokens), self::abbreviateNumber($s->contextWindow))
                    : '';
                if ('' !== $ctxDetail) {
                    $segments[] = new FooterSegment(text: $ctxDetail, priority: 13, color: ThemeColor::Muted);
                }

                // ── Throughput (priority 15) ──
                if ($s->outputTokens > 0) {
                    $elapsed = microtime(true) - $s->sessionStartTime;
                    if ($elapsed > 0) {
                        $tps = $s->outputTokens / $elapsed;
                        $segments[] = new FooterSegment(
                            text: \sprintf('⚡ %.1f t/s', $tps),
                            priority: 15,
                            color: ThemeColor::Success,
                        );
                    }
                }

                // ── Elapsed time (priority 20) ──
                $elapsed = microtime(true) - $s->sessionStartTime;
                if ($elapsed >= 1) {
                    $hours = (int) ($elapsed / 3600);
                    $minutes = (int) (($elapsed % 3600) / 60);
                    $seconds = $elapsed % 60;

                    if ($hours > 0) {
                        $timeStr = \sprintf('%dh%dm', $hours, $minutes);
                    } elseif ($minutes > 0) {
                        $timeStr = \sprintf('%dm%ds', $minutes, $seconds);
                    } else {
                        $timeStr = \sprintf('%ds', $seconds);
                    }
                    $segments[] = new FooterSegment(
                        text: \sprintf('⏱ %s', $timeStr),
                        priority: 20,
                        color: ThemeColor::Dim,
                    );
                } else {
                    $segments[] = new FooterSegment(
                        text: '⏱ 0s',
                        priority: 20,
                        color: ThemeColor::Dim,
                    );
                }

                // ── CWD (priority 25) ──
                if ('' !== $s->cwd) {
                    $segments[] = new FooterSegment(
                        text: \sprintf('⌂ %s', $s->cwd),
                        priority: 25,
                        color: ThemeColor::Muted,
                    );
                }

                // ── Branch (priority 30) ──
                if ('' !== $s->branch) {
                    $segments[] = new FooterSegment(
                        text: \sprintf('⎇ %s', $s->branch),
                        priority: 30,
                        color: ThemeColor::Accent,
                    );
                }

                return $segments;
            }

            /**
             * Map reasoning level to a thinking-indicator colour.
             *
             * Matches the pi reference convention: off/missing → muted,
             * low → dim, medium → accent, high → warning.
             */
            private static function thinkingColor(string $reasoning): ThemeColor
            {
                return match ($reasoning) {
                    'high', 'xhigh' => ThemeColor::Warning,
                    'medium' => ThemeColor::Accent,
                    'low', 'minimal' => ThemeColor::Dim,
                    default => ThemeColor::Muted,
                };
            }

            private static function abbreviateNumber(int $n): string
            {
                if ($n >= 1_000_000) {
                    return \sprintf('%.1fM', $n / 1_000_000);
                }

                if ($n >= 1_000) {
                    return \sprintf('%.1fk', $n / 1_000);
                }

                return (string) $n;
            }
        };
    }

    // ── Helpers ──

    private static function shortModelName(string $model): string
    {
        $slash = strpos($model, '/');
        if (false !== $slash) {
            return substr($model, $slash + 1);
        }

        return $model;
    }

    private static function abbreviatePath(string $path): string
    {
        $home = getenv('HOME');
        if (false !== $home && '' !== $home && str_starts_with($path, $home)) {
            return '~'.substr($path, \strlen($home));
        }

        return $path;
    }
}
