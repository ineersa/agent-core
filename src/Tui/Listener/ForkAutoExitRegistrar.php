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
 *   2. Stops the TUI event loop so the process can finalize
 *      and write the exit marker to stdout.
 *
 * The controller-side ForkRunTerminalWatcher (AppRuntimeInternals)
 * handles handoff validation, repair, and artifact writing in the
 * controller process.  This registrar only needs to detect terminal
 * state and stop the TUI — it does NOT call AppAgent services.
 *
 * This registrar is a no-op for non-fork TUI sessions.
 */
final readonly class ForkAutoExitRegistrar implements TuiListenerRegistrar
{
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

        // Register a tick handler that checks terminal state and stops the TUI.
        $context->ticks->add(static function () use ($context): ?bool {
            $handle = $context->state->handle;
            if (null === $handle) {
                return null; // Not started yet — keep polling at normal rate
            }

            // Only act when the run reaches a terminal state.
            if (!$context->state->activity->isTerminal()) {
                return null;
            }

            // Terminal state reached — stop the TUI.
            // The controller-side ForkRunTerminalWatcher has already handled
            // handoff validation, repair, and artifact writing.
            $context->tui->stop();

            return true; // Active tick — stop event loop
        });
    }
}
