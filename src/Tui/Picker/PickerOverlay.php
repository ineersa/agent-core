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
     * Mount the picker overlay in the ChatScreen overlay slot.
     *
     * Default {@see PickerOverlayPlacementEnum::AfterEditor} matches completion menus
     * (below editor). /tree and /rewind pass {@see PickerOverlayPlacementEnum::BeforeEditor}
     * because dynamic list rebuilds in the below-editor band caused live stale rows and
     * footer/status bleed in tmux.
     */
    public function mount(
        Tui $tui,
        ChatScreen $screen,
        SelectListWidget $listWidget,
        TextWidget $header,
        PickerOverlayPlacementEnum $placement = PickerOverlayPlacementEnum::AfterEditor,
    ): void {
        if ($this->isOpen) {
            return;
        }

        $this->screen = $screen;
        $this->listWidget = $listWidget;

        $this->container = new ContainerWidget();
        $this->container->add($header);
        $this->container->add($this->listWidget);

        if (PickerOverlayPlacementEnum::BeforeEditor === $placement) {
            $screen->insertOverlayBeforeEditor($this->container);
        } else {
            $screen->insertOverlayAfterEditor($this->container);
        }
        $tui->setFocus($this->listWidget);
        // Force full clear+redraw on mount so ScreenWriter does not leave stale
        // picker rows in the overlay slot when incremental dirty regions miss prior list height.
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

    /**
     * After list item rebuild on navigation: invalidate overlay subtree only.
     *
     * Non-forced render lets ScreenWriter differentially update the overlay
     * band; full reset on every arrow caused visible flicker/jump in live TUI.
     * Mount/close still use requestRender(true) to avoid stale rows on open.
     */
    public function invalidateListPaint(Tui $tui): void
    {
        $this->container?->invalidate();
        $this->listWidget?->invalidate();
        $tui->requestRender();
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
