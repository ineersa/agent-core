<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Symfony\Component\Tui\Event\TickEvent;

/**
 * Per-run tick dispatcher allowing multiple listener registrars to
 * register tick callbacks without overwriting each other.
 *
 * Symfony TUI's {@see \Symfony\Component\Tui\Tui::onTick()} is a
 * single-slot setter. This dispatcher replaces it as the single
 * Symfony callback and multiplexes to all registered handlers.
 */
final class TuiTickDispatcher
{
    /** @var list<callable(TickEvent): ?bool> */
    private array $handlers = [];

    /**
     * Register a tick handler.
     *
     * The callable receives the TickEvent and returns:
     *  - true  to keep ticking at full speed
     *  - false to pause ticking briefly
     *  - null  to defer to other handlers (will use null fallback)
     */
    public function add(callable $handler): void
    {
        $this->handlers[] = $handler;
    }

    /**
     * Dispatch the tick to all registered handlers.
     *
     * Returns true if any handler returned true; otherwise null
     * (which Symfony TUI interprets as "use the fallback idle polling rate").
     */
    public function dispatch(TickEvent $event): ?bool
    {
        $anyTrue = false;

        foreach ($this->handlers as $handler) {
            $result = $handler($event);
            if (true === $result) {
                $anyTrue = true;
            }
        }

        return $anyTrue ? true : null;
    }
}
