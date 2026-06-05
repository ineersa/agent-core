<?php

declare(strict_types=1);

namespace Ineersa\Tui\Picker;

use Ineersa\Tui\Screen\ChatScreen;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Shared lifecycle for picker overlays (mount, focus, close).
 *
 * Owns the ContainerWidget, SelectListWidget, Tui/ChatScreen refs,
 * and isOpen state that is shared across picker overlay controllers.
 * Controllers provide item-building and selection-specific
 * behavior; this class handles widget tree manipulation.
 */
final class PickerOverlay
{
    private ?ContainerWidget $container = null;
    private ?SelectListWidget $listWidget = null;
    private bool $isOpen = false;
    private ?Tui $tui = null;
    private ?ChatScreen $screen = null;

    /**
     * Mount the overlay into the TUI widget tree.
     *
     * Creates a ContainerWidget wrapping the header and list widgets,
     * adds it to the TUI, and sets focus to the list.
     */
    public function mount(Tui $tui, ChatScreen $screen, SelectListWidget $listWidget, TextWidget $header): void
    {
        if ($this->isOpen) {
            return;
        }

        $this->tui = $tui;
        $this->screen = $screen;
        $this->listWidget = $listWidget;

        $this->container = new ContainerWidget();
        $this->container->add($header);
        $this->container->add($this->listWidget);

        $tui->add($this->container);
        $tui->setFocus($this->listWidget);
        $tui->requestRender(true);
        $this->isOpen = true;
    }

    /**
     * Remove the overlay from the TUI widget tree and reset state.
     *
     * Removing the container automatically detaches all children
     * (header + list) via the WidgetTree lifecycle.
     */
    public function close(): void
    {
        if (null !== $this->container && null !== $this->tui) {
            $this->tui->remove($this->container);
        }

        $this->container = null;
        $this->listWidget = null;
        $this->tui = null;
        $this->screen = null;
        $this->isOpen = false;
    }

    public function isOpen(): bool
    {
        return $this->isOpen;
    }

    /**
     * The currently mounted SelectListWidget, or null if not mounted.
     */
    public function listWidget(): ?SelectListWidget
    {
        return $this->listWidget;
    }

    /**
     * The currently mounted ChatScreen reference, or null if not mounted.
     */
    public function screen(): ?ChatScreen
    {
        return $this->screen;
    }
}
