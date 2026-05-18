<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\AppConfig;
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
 *  - Seeds model/reasoning from session metadata (or AppConfig fallback)
 *  - Detects cwd (short: last 2 path segments) and git branch
 *  - Records the session start time
 *  - Looks up the model's context window from the Hatfield catalog (if available)
 *  - Registers a FooterSegmentProvider on the footer data provider
 *
 * On each tick, token usage is accumulated into TuiSessionState by
 * RuntimeEventPoller::extractFooterUsage(). A tick handler refreshes
 * the screen so the footer re-renders with updated elapsed time and
 * throughput.
 *
 * Footer segment order (matching Pi reference):
 *   ◆ model  |  input/output $cost pct% used/contextWindow  |  ⚡ t/s  |  ⏱ elapsed  |  ⌂ cwd  |  ⎇ branch
 *
 * Diamond color reflects reasoning level; model text is Accent.
 * Reasoning is NOT rendered as a text segment — only the diamond colour conveys it.
 */
final readonly class FooterStateListener implements TuiListenerRegistrar
{
    public function __construct(
        private HatfieldSessionStore $sessionStore,
        private AppConfig $appConfig,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $state = $context->state;

        // Seed model/reasoning from session metadata
        $fullModel = '';
        $reasoning = '';
        $meta = $this->sessionStore->loadMetadata($state->sessionId);
        if (null !== $meta) {
            $v = $meta['model'] ?? '';
            $fullModel = \is_string($v) ? $v : '';
            $v = $meta['reasoning'] ?? '';
            $reasoning = \is_string($v) ? $v : '';
        }

        // Fallback: StartRunRequest (first run before session persisted)
        if ('' === $fullModel && null !== $state->request) {
            $fullModel = (string) ($state->request->model ?? '');
            $reasoning = (string) ($state->request->reasoning ?? '');
        }

        // Fallback: AppConfig default model
        if ('' === $fullModel && null !== $this->appConfig->ai) {
            $defaultModel = $this->appConfig->ai->defaultModel;
            if (null !== $defaultModel && '' !== $defaultModel) {
                $fullModel = $defaultModel;
            }

            // Seed default reasoning when available
            if ('' === $reasoning && null !== $this->appConfig->ai->defaultReasoning && '' !== $this->appConfig->ai->defaultReasoning) {
                $reasoning = $this->appConfig->ai->defaultReasoning;
            }
        }

        // Short model display: strip provider prefix for visible name
        $state->footerModel = self::shortModelName($fullModel);
        $state->footerReasoning = $reasoning;

        // Look up context window from Hatfield catalog for the selected model
        $state->contextWindow = self::resolveContextWindow($this->appConfig, $fullModel);

        // Set session start time
        if (0.0 === $state->sessionStartTime) {
            $state->sessionStartTime = microtime(true);
        }

        // Detect cwd (short: last 2 path segments, no ~)
        $cwd = getcwd();
        $state->cwd = false !== $cwd ? self::shortCwd($cwd) : '';

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

    // ── Detect git branch ──

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

    /**
     * Resolve the context window (in tokens) for a given full model string.
     *
     * Looks up the model in the Hatfield catalog. Returns 0 when not found.
     */
    private static function resolveContextWindow(AppConfig $appConfig, string $fullModel): int
    {
        $catalog = $appConfig->catalog;
        if (null === $catalog || '' === $fullModel) {
            return 0;
        }

        $ref = AiModelReference::tryParse($fullModel);
        if (null === $ref) {
            return 0;
        }

        $definition = $catalog->getModel($ref);

        return null !== $definition ? ($definition->contextWindow ?? 0) : 0;
    }

    // ── Provider factory ──

    /**
     * Create a FooterSegmentProvider that reads from TuiSessionState at render time.
     *
     * Segment priorities match the Pi reference order:
     *   ◆ model (0)
     *     token block: input/output(10)  $cost(11)  pct%(12)  used/contextWindow(13)
     *     ⚡ t/s (15, optional)
     *     ⏱ elapsed (20)
     *     ⌂ cwd (25)
     *     ⎇ branch (30)
     *
     * Reasoning level is NOT shown as a text segment — it only affects the diamond colour.
     * Cost defaults to $0.00, context defaults to 0%.
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

                // ── Group 1: ◆ model (priority 0) ──
                // Diamond colour reflects reasoning; model text is always Accent.
                $modelName = '' !== $s->footerModel ? $s->footerModel : 'no-model';
                $thinkColor = self::thinkingColor($s->footerReasoning);

                // Diamond in thinking colour, model in accent
                $segments[] = new FooterSegment(
                    text: '◆',
                    priority: 0,
                    color: $thinkColor,
                );
                $segments[] = new FooterSegment(
                    text: $modelName,
                    priority: 1,
                    color: ThemeColor::Accent,
                );

                // ── Group 2: Token stats block (priorities 10-13) ──
                // Small priority gaps (< 5) keep these space-separated
                $in = self::fmt($s->inputTokens);
                $out = self::fmt($s->outputTokens);
                $segments[] = new FooterSegment(
                    text: \sprintf('%s/%s', $in, $out),
                    priority: 10,
                    color: ThemeColor::Accent,
                );

                // Cost: always show as $0.00 when zero
                $costStr = \sprintf('$%.2f', $s->totalCost);
                $segments[] = new FooterSegment(
                    text: $costStr,
                    priority: 11,
                    color: ThemeColor::Warning,
                );

                // Context usage percentage and detail
                $ctxDetail = '';
                $pctColor = ThemeColor::Success;

                if ($s->contextWindow > 0) {
                    $used = $s->inputTokens;
                    $pct = $used > 0 ? min(100, ($used / $s->contextWindow) * 100) : 0.0;
                    $pctColor = $pct > 75 ? ThemeColor::Error : ($pct > 50 ? ThemeColor::Warning : ThemeColor::Success);
                    $ctxDetail = \sprintf('%.0f%% %s/%s', $pct, self::fmt($used), self::fmt($s->contextWindow));
                } else {
                    // Unknown context window: just show 0%
                    $pctColor = ThemeColor::Success;
                    $ctxDetail = '0%';
                }

                $segments[] = new FooterSegment(
                    text: $ctxDetail,
                    priority: 12,
                    color: $pctColor,
                );

                // ── Group 3: Throughput (priority 15, optional) ──
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

                // ── Group 4: Elapsed time (priority 20) ──
                $elapsed = microtime(true) - $s->sessionStartTime;
                $timeStr = self::formatElapsed($elapsed);
                $segments[] = new FooterSegment(
                    text: \sprintf('⏱ %s', $timeStr),
                    priority: 20,
                    color: ThemeColor::Dim,
                );

                // ── Group 5: CWD (priority 25) ──
                if ('' !== $s->cwd) {
                    $segments[] = new FooterSegment(
                        text: \sprintf('⌂ %s', $s->cwd),
                        priority: 25,
                        color: ThemeColor::Muted,
                    );
                }

                // ── Group 6: Branch (priority 30) ──
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
             * Matches the Pi reference convention: off/missing → muted,
             * low → dim, medium → accent, high → warning, xhigh → warning.
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

            /**
             * Format a number with k-suffix for values >= 1000 (matching Pi fmt()).
             * Uses 1 decimal place for k-suffix; no M-suffix.
             */
            private static function fmt(int $n): string
            {
                if ($n >= 1_000) {
                    return \sprintf('%.1fk', $n / 1_000);
                }

                return (string) $n;
            }

            /**
             * Format elapsed seconds as a compact human-readable string.
             * Matches Pi formatElapsed().
             */
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
        };
    }

    // ── Helpers ──

    /**
     * Strip the provider prefix from a model reference string.
     * "deepseek/deepseek-v4-pro" → "deepseek-v4-pro"
     * Non-prefixed models returned unchanged.
     */
    private static function shortModelName(string $model): string
    {
        $slash = strpos($model, '/');
        if (false !== $slash) {
            return substr($model, $slash + 1);
        }

        return $model;
    }

    /**
     * Compact path: last 2 components of normalized path.
     * Matches Pi direction of showing only the final segments.
     */
    private static function shortCwd(string $path): string
    {
        $parts = explode('/', $path);
        $parts = array_values(array_filter($parts, static fn (string $p): bool => '' !== $p));

        if (\count($parts) >= 2) {
            return $parts[\count($parts) - 2].'/'.$parts[\count($parts) - 1];
        }

        return $parts[0] ?? '';
    }
}
