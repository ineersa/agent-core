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
 * Pickers render in the below-editor overlay slot (same band as completion menus).
 * {@see SelectListWidget} owns keyboard selection styling; controllers must not rebuild
 * item lists on arrow navigation just to accent the selected row.
 */
final class PickerOverlay
{
    private ?ContainerWidget $container = null;
    private ?SelectListWidget $listWidget = null;
    private bool $isOpen = false;
    private ?ChatScreen $screen = null;

    public function mount(
        Tui $tui,
        ChatScreen $screen,
        SelectListWidget $listWidget,
        TextWidget $header,
    ): void {
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
        // Force full clear+redraw on mount so ScreenWriter does not leave stale
        // picker rows in the overlay slot when incremental dirty regions miss prior list height.
        $tui->requestRender(true);
        $this->isOpen = true;
    }

    public function close(bool $requestRender = true): void
    {
        if (null !== $this->container && null !== $this->screen) {
            $this->screen->removeOverlay($this->container);
            if ($requestRender) {
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

    public function listWidget(): ?SelectListWidget
    {
        return $this->listWidget;
    }

    public function screen(): ?ChatScreen
    {
        return $this->screen;
    }
}
