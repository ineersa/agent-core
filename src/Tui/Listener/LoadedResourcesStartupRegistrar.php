<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Symfony\Component\Tui\Event\InputEvent;

/**
 * Toggles loaded-resources source-path expansion with ctrl+r.
 */
final readonly class LoadedResourcesStartupRegistrar implements TuiListenerRegistrar
{
    public function register(TuiRuntimeContext $context): void
    {
        $screen = $context->screen;
        $tui = $context->tui;

        $tui->addListener(static function (InputEvent $event) use ($screen, $tui): void {
            if ("\x12" !== $event->getData()) { // ctrl+r
                return;
            }

            if (!$screen->hasLoadedResourcesBlock()) {
                return;
            }

            $event->stopPropagation();

            $screen->toggleLoadedResourcesExpanded();
            $tui->requestRender();
        }, priority: 50);
    }
}
