<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

/**
 * Per-iteration session lifecycle event dispatcher.
 *
 * Analogous to {@see TuiTickDispatcher}: replaces a single-slot concept
 * (one lifecycle event) with a subscriber list so multiple listener
 * registrars and future slash-command handlers can react to session
 * transitions without overwriting one another.
 *
 * InteractiveMode creates a fresh dispatcher each loop iteration so
 * stale subscriptions never leak across sessions.  Subscribers are
 * registered during {@see TuiListenerRegistrar::register()} and
 * dispatched after all registrars have been wired up.
 */
final class TuiSessionLifecycleDispatcher
{
    /** @var list<callable(TuiSessionLifecycleEventDTO): void> */
    private array $subscribers = [];

    /**
     * Register a lifecycle event handler.
     *
     * Handlers are called in registration order when
     * {@see dispatch()} is invoked.
     */
    public function subscribe(callable $handler): void
    {
        $this->subscribers[] = $handler;
    }

    /**
     * Dispatch a lifecycle event to all registered subscribers.
     *
     * Subscriber exceptions propagate immediately — later subscribers
     * are NOT called after a throw.  It is the subscriber's
     * responsibility to catch exceptions internally for local
     * degradation; otherwise the error surfaces as a TUI error and
     * short-circuits remaining handlers.
     */
    public function dispatch(TuiSessionLifecycleEventDTO $event): void
    {
        foreach ($this->subscribers as $subscriber) {
            $subscriber($event);
        }
    }
}
