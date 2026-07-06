<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\FileRewind;

use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class PickerOverlay
{
    private ?ContainerWidget $container = null;
    private ?AbstractWidget $mountedWidget = null;
    private bool $isOpen = false;

    public function mount(
        TuiExtensionContextInterface $tui,
        SelectListWidget $listWidget,
        TextWidget $header,
    ): void {
        if ($this->isOpen) {
            return;
        }

        $this->container = new ContainerWidget();
        $this->container->add($header);
        $this->container->add($listWidget);

        $tui->insertOverlayAfterEditor($this->container);
        $tui->setFocus($listWidget);
        $tui->requestRender(true);
        $this->mountedWidget = $this->container;
        $this->isOpen = true;
    }

    public function close(TuiExtensionContextInterface $tui, bool $requestRender = true): void
    {
        if (null !== $this->mountedWidget) {
            $tui->removeOverlay($this->mountedWidget);
            if ($requestRender) {
                $tui->requestRender(true);
            }
        }

        $this->container = null;
        $this->mountedWidget = null;
        $this->isOpen = false;
    }

    public function isOpen(): bool
    {
        return $this->isOpen;
    }
}
