<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Auto-exit registrar for fork mode TUI sessions.
 *
 * When the TUI starts a fork child run (detected via
 * StartRunRequest::options['fork_mode']), this registrar
 * attaches a tick handler that:
 *
 *   1. Waits until the run's activity state is terminal
 *      (Completed, Failed, or Cancelled).
 *   2. Waits for the controller-side ForkRunTerminalWatcher to
 *      write the .fork-finalized marker file, proving artifacts
 *      are fully written (handoff.md, fork-metadata.json, etc.).
 *   3. Stops the TUI event loop so the process can write the
 *      exit marker to stdout.
 *
 * The .fork-finalized marker prevents a race between the TUI
 * receiving the terminal runtime event and the controller-side
 * watcher finishing artifact writes.  Without this barrier, the TUI
 * could exit before artifacts are complete, losing result data.
 *
 * If the marker does not appear within MARKER_WAIT_TIMEOUT seconds,
 * the TUI stops anyway with a diagnostic log.  This prevents a
 * deadlock in case the watcher/controller fails silently.
 * FORK-05's completion watcher can then retrieve the partial
 * artifacts (fork-metadata.json with Failed/Cancelled status).
 *
 * The marker file is checked from the fork_result_dir option.
 * If the option is not set, falls back to immediate stop on
 * terminal (graceful degradation).
 *
 * This registrar is a no-op for non-fork TUI sessions.
 */
final readonly class ForkAutoExitRegistrar implements TuiListenerRegistrar
{
    /** Marker filename written by ForkRunTerminalWatcher after finalization. */
    private const string FINALIZED_MARKER = '.fork-finalized';

    /**
     * Maximum seconds to wait for the .fork-finalized marker before
     * stopping the TUI regardless.  Prevents deadlock when the
     * controller-side ForkRunTerminalWatcher fails silently.
     *
     * Short in tests (TUI tick rate ~50ms), production-safe at 30s
     * because the fork run and finalization should complete well
     * within that window.  If the marker does not appear, the TUI
     * stops and the parent (FORK-05 completion watcher) can retrieve
     * partial diagnostics from fork-metadata.json and events.jsonl.
     */
    private const float MARKER_WAIT_TIMEOUT = 30.0;

    public function register(TuiRuntimeContext $context): void
    {
        $request = $context->state->request;
        if (null === $request) {
            return;
        }

        // Only activate in fork mode (scalar option from process transport).
        if (true !== ($request->options['fork_mode'] ?? false)) {
            return;
        }

        // Resolve result directory for marker file check.
        $resultDir = isset($request->options['fork_result_dir'])
            ? (string) $request->options['fork_result_dir']
            : '';

        // Track whether we've started waiting for the marker.
        $waitingForTerminal = true;
        $waitingForMarker = false;
        $markerWaitStart = null; // microtime when we entered marker-wait phase

        // Register a tick handler that checks terminal state and finalization marker.
        $context->ticks->add(static function () use (
            $context,
            $resultDir,
            &$waitingForTerminal,
            &$waitingForMarker,
            &$markerWaitStart,
        ): ?bool {
            $handle = $context->state->handle;
            if (null === $handle) {
                return null; // Not started yet — keep polling at normal rate
            }

            // Phase 1: Wait for terminal state.
            if ($waitingForTerminal) {
                if (!$context->state->activity->isTerminal()) {
                    return null; // Keep polling — run still active
                }
                $waitingForTerminal = false;
                $waitingForMarker = true;
                $markerWaitStart = microtime(true);

                // Fall through to check marker (may already exist).
            }

            // Phase 2: Wait for finalization marker (if result dir is known).
            if ($waitingForMarker && '' !== $resultDir) {
                // Check for timeout to prevent deadlock.
                if (null !== $markerWaitStart && (microtime(true) - $markerWaitStart) >= self::MARKER_WAIT_TIMEOUT) {
                    // Timeout reached — stop without marker.
                    // The .fork-finalized marker was not written within the timeout.
                    // This is a degraded stop: the parent (FORK-05 completion watcher)
                    // can still retrieve partial diagnostics from fork-metadata.json
                    // and events.jsonl in the result directory.
                    $waitingForMarker = false;
                } else {
                    $markerFile = $resultDir.'/'.self::FINALIZED_MARKER;
                    if (!is_file($markerFile)) {
                        // Marker not yet written — return true to keep tick active
                        // and poll at the normal TUI tick rate (~50ms).
                        return true;
                    }
                    $waitingForMarker = false;
                }
            }

            // Terminal state reached AND marker confirmed (or timed out, or no result dir).
            $context->tui->stop();

            return true; // Active tick — stop event loop
        });
    }
}
