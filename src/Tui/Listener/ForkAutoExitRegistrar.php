<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Auto-exit registrar for fork mode TUI sessions.
 *
 * When the TUI starts a fork child run (detected via
 * StartRunRequest::options['fork_terminal_callback']), this registrar
 * attaches a tick handler that:
 *
 *   1. Waits until the run's activity state is terminal
 *      (Completed, Failed, or Cancelled).
 *   2. Calls the terminal callback which runs handoff validation,
 *      repair, and artifact writing (provided by ForkRunTerminalWatcher
 *      in AppRuntimeInternals).
 *   3. When the callback returns 'done' or 'exit', stops the TUI
 *      so the process can finalize and exit.
 *   4. When the callback returns 'repairing', keeps the TUI alive
 *      for the next turn.
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

        $terminalCallback = $request->options['fork_terminal_callback'] ?? null;
        if (!\is_callable($terminalCallback)) {
            return;
        }

        // Register a tick handler that checks terminal state and invokes
        // the callback. The tick handler runs after each poll cycle.
        $context->ticks->add(static function () use ($context, $terminalCallback): ?bool {
            $handle = $context->state->handle;
            if (null === $handle) {
                return null; // Not started yet — keep polling at normal rate
            }
            $runId = $handle->runId;

            // Only act when the run reaches a terminal state.
            if (!$context->state->activity->isTerminal()) {
                return null;
            }

            // Call the terminal callback. The callback may return:
            //   'done'      — finalization complete, exit TUI
            //   'exit'      — same as done
            //   'repairing' — repair followUp sent, waiting for new response
            //   null        — not ready yet, keep polling
            $result = $terminalCallback($runId);

            if ('done' === $result || 'exit' === $result) {
                $context->tui->stop();

                return true; // Active tick — stop event loop
            }

            if ('repairing' === $result) {
                // Repair in progress — keep the TUI alive.
                // The callback already sent a followUp; the event poller
                // will pick up the new response events.
                return null;
            }

            return null;
        });
    }
}
