<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Contract for stateless TUI listener registrars.
 *
 * Services implementing this interface and tagged with `app.tui_listener`
 * are autowired into InteractiveMode and called once per TUI session
 * to register their event listeners on the TUI instance.
 *
 * Registrars must be stateless — they receive all per-run state via
 * the context parameter. They must not be constructed with run-specific
 * data like widgets, theme, or client handles.
 */
interface TuiListenerRegistrar
{
    /**
     * Register event listeners, tick callbacks, or input handlers
     * on the TUI instance using the provided runtime context.
     */
    public function register(TuiRuntimeContext $context): void;
}
