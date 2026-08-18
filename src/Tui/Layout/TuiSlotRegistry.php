<?php

declare(strict_types=1);

namespace Ineersa\Tui\Layout;

/**
 * Central registry for replaceable TUI slots.
 *
 * Stores terminal input handler entries (priority + native InputEvent
 * listener). Status entries and working-message state were moved into
 * ChatScreen (sole owner/writer) when the chrome migrated to directly
 * mounted native widgets; the registry no longer holds state that must
 * paint.
 */
final class TuiSlotRegistry
{
    /**
     * Native TUI InputEvent listeners registered by the host.
     *
     * Each entry is a callable receiving a Symfony InputEvent (so it can
     * stop propagation) plus the priority it must be registered with, so
     * slot handlers interleave with the other native listeners by priority.
     *
     * @var list<array{priority: int, handler: callable}>
     */
    private array $inputHandlers = [];

    /* ───────── Input handlers ───────── */

    /**
     * Register a native TUI InputEvent listener.
     *
     * The handler is a Symfony InputEvent listener: its first parameter
     * must be type-hinted with the event class so the host can register it
     * via {@see \Symfony\Component\Tui\Tui::addListener()} with the given
     * priority. Equal priorities keep registration order.
     *
     * @param callable $handler Symfony InputEvent listener
     */
    public function addInputHandler(callable $handler, int $priority = InputPriority::EXTENSION_DEFAULT): void
    {
        $this->inputHandlers[] = ['priority' => $priority, 'handler' => $handler];
    }

    /**
     * Registered native input listeners in registration order.
     *
     * @return list<array{priority: int, handler: callable}>
     */
    public function getInputHandlers(): array
    {
        return $this->inputHandlers;
    }
}
