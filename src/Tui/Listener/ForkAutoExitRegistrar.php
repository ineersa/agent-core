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

        // Register a tick handler that checks terminal state and finalization marker.
        $context->ticks->add(static function () use (
            $context,
            $resultDir,
            &$waitingForTerminal,
            &$waitingForMarker,
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

                // Fall through to check marker (may already exist).
            }

            // Phase 2: Wait for finalization marker (if result dir is known).
            if ($waitingForMarker && '' !== $resultDir) {
                $markerFile = $resultDir.'/'.self::FINALIZED_MARKER;
                if (!is_file($markerFile)) {
                    // Marker not yet written — return true to keep tick active
                    // and poll at the normal TUI tick rate (~50ms).
                    return true;
                }
                $waitingForMarker = false;
            }

            // Terminal state reached AND marker confirmed (or no result dir).
            $context->tui->stop();

            return true; // Active tick — stop event loop
        });
    }
}
