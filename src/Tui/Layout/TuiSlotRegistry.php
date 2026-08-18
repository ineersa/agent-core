<?php

declare(strict_types=1);

namespace Ineersa\Tui\Layout;

/**
 * Central registry for replaceable TUI slots.
 *
 * Stores:
 *   - Status text entries keyed by section name.
 *   - Working message text and visibility flag.
 *   - Terminal input handler entries (priority + native InputEvent listener).
 *
 * Header/footer/editor/extension-widget replacement slots were removed when
 * ChatScreen migrated to directly mounted native Symfony widgets; status,
 * working, and input-handler paths remain (input behavior owned by TUI-04).
 */
final class TuiSlotRegistry
{
    /** @var array<string, string> */
    private array $statusEntries = [];

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

    private string $workingMessage = '';
    private bool $workingVisible = true;

    /* ───────── Status entries ───────── */

    public function setStatus(string $key, ?string $text): void
    {
        if (null === $text) {
            unset($this->statusEntries[$key]);
        } else {
            $this->statusEntries[$key] = $text;
        }
    }

    /**
     * @return array<string, string>
     */
    public function getStatusEntries(): array
    {
        return $this->statusEntries;
    }

    /* ───────── Working state ───────── */

    public function setWorkingMessage(?string $message): void
    {
        $this->workingMessage = $message ?? '';
    }

    public function getWorkingMessage(): string
    {
        return $this->workingMessage;
    }

    public function setWorkingVisible(bool $visible): void
    {
        $this->workingVisible = $visible;
    }

    public function isWorkingVisible(): bool
    {
        return $this->workingVisible;
    }

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
