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
    private ?ChatScreen $screen = null;

    /**
     * Mount the overlay below the editor via the ChatScreen overlay API.
     *
     * Creates a ContainerWidget wrapping the header and list widgets,
     * inserts it below the editor (same visual slot as completion menus),
     * and sets focus to the list.
     *
     * Uses {@see ChatScreen::insertOverlayAfterEditor()} so the picker
     * renders above the footer, not below it.
     */
    public function mount(Tui $tui, ChatScreen $screen, SelectListWidget $listWidget, TextWidget $header): void
    {
        if ($this->isOpen) {
            return;
        }

        $this->screen = $screen;
        $this->listWidget = $listWidget;

        $this->container = new ContainerWidget();
        $this->container->add($header);
        $this->container->add($this->listWidget);

        $screen->insertOverlayAfterEditor($this->container);
        $tui->setFocus($this->listWidget);
        // Use a non-forced render request so ScreenWriter performs
        // a differential update instead of a full clear+redraw.
        // fullRender(clear=true) writes \x1b[2J\x1b[3J\x1b[H inside
        // DECSET 2026 synchronized-output brackets, which causes
        // visible flicker and scrollback artifacts in some terminal
        // multiplexers.  When the diff cannot be handled incrementally
        // (e.g. the first changed line is outside the viewport),
        // ScreenWriter naturally falls back to fullRender on its own.
        // Force a full clear+redraw when opening pickers. Incremental
        // ScreenWriter updates can leave stale picker rows in the overlay
        // slot (below editor) when the viewport dirty region does not cover
        // prior list height — users see duplicated headers/turn rows.
        $tui->requestRender(true);
        $this->isOpen = true;
    }

    /**
     * Remove the overlay from the TUI widget tree and reset state.
     *
     * Removing the container automatically detaches all children
     * (header + list) via the WidgetTree lifecycle.
     *
     * @param bool $requestRender Whether to schedule a TUI repaint
     *                            after removal.  Default true (used by Esc/cancel — repaint
     *                            so the overlay disappears visually).  Pass false when the
     *                            TUI is about to stop for a session switch — the render
     *                            would paint a torn-down widget/projector state, causing
     *                            visual corruption or cursor freeze.
     */
    public function close(bool $requestRender = true): void
    {
        if (null !== $this->container && null !== $this->screen) {
            $this->screen->removeOverlay($this->container);
            if ($requestRender) {
                // Request a render so the TUI repaints without the
                // overlay on the next tick instead of waiting for a
                // natural tick — avoids a visible stale frame.
                $this->screen->requestRender(true);
            }
        }

        $this->container = null;
        $this->listWidget = null;
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
