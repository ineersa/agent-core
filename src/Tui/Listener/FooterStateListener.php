<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Footer\FooterSegment;
use Ineersa\Tui\Footer\FooterSegmentProvider;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionState;
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
                $segments = [];
                $s = $this->state;

                // Model segment (priority 0)
                if ('' !== $s->footerModel) {
                    $segments[] = new FooterSegment(
                        text: \sprintf('◆ %s', $s->footerModel),
                        priority: 0,
                    );
                }

                // Reasoning segment (priority 5)
                if ('' !== $s->footerReasoning) {
                    $segments[] = new FooterSegment(
                        text: \sprintf('│ %s', $s->footerReasoning),
                        priority: 5,
                    );
                }

                // Token usage segment (priority 10)
                if ($s->inputTokens > 0 || $s->outputTokens > 0) {
                    $in = self::abbreviateNumber($s->inputTokens);
                    $out = self::abbreviateNumber($s->outputTokens);
                    $segments[] = new FooterSegment(
                        text: \sprintf('%si %so', $in, $out),
                        priority: 10,
                    );
                }

                // Throughput segment (priority 15)
                if ($s->outputTokens > 0) {
                    $elapsed = microtime(true) - $s->sessionStartTime;
                    if ($elapsed > 0) {
                        $tps = $s->outputTokens / $elapsed;
                        $segments[] = new FooterSegment(
                            text: \sprintf('⚡ %.1f t/s', $tps),
                            priority: 15,
                        );
                    }
                }

                // Elapsed time segment (priority 20)
                $elapsed = microtime(true) - $s->sessionStartTime;
                if ($elapsed >= 1) {
                    $hours = (int) ($elapsed / 3600);
                    $minutes = (int) (($elapsed % 3600) / 60);
                    $seconds = ($elapsed % 60);

                    if ($hours > 0) {
                        $segments[] = new FooterSegment(
                            text: \sprintf('⏱ %dh%dm', $hours, $minutes),
                            priority: 20,
                        );
                    } elseif ($minutes > 0) {
                        $segments[] = new FooterSegment(
                            text: \sprintf('⏱ %dm%ds', $minutes, $seconds),
                            priority: 20,
                        );
                    }
                }

                // CWD segment (priority 25)
                if ('' !== $s->cwd) {
                    $segments[] = new FooterSegment(
                        text: \sprintf('⌂ %s', $s->cwd),
                        priority: 25,
                    );
                }

                // Branch segment (priority 30)
                if ('' !== $s->branch) {
                    $segments[] = new FooterSegment(
                        text: \sprintf('⎇ %s', $s->branch),
                        priority: 30,
                    );
                }

                return $segments;
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
