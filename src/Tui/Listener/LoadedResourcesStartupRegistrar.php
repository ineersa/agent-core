<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Toggles loaded-resources source-path expansion with ctrl+r.
 */
final readonly class LoadedResourcesStartupRegistrar implements TuiListenerRegistrar
{
    public function register(TuiRuntimeContext $context): void
    {
        $screen = $context->screen;
        $tui = $context->tui;

        $screen->registry()->addInputHandler(static function (string $data) use ($screen, $tui): void {
            if ("\x12" !== $data) { // ctrl+r
                return;
            }

            if (!$screen->hasLoadedResourcesBlock()) {
                return;
            }

            $screen->toggleLoadedResourcesExpanded();
            $tui->requestRender();
        });
    }
}
