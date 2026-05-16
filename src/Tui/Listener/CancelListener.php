<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Symfony\Component\Tui\Event\CancelEvent;

/**
 * Handles Escape key — clears the editor text.
 *
 * Implements TuiListenerRegistrar for DI-driven registration.
 */
final class CancelListener implements TuiListenerRegistrar
{
    public function register(TuiRuntimeContext $context): void
    {
        $screen = $context->screen;

        $context->tui->addListener(static function (CancelEvent $event) use ($screen): void {
            $screen->clearEditor();
        });
    }
}
