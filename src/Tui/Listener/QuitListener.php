<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Symfony\Component\Tui\Event\QuitEvent;

/**
 * Handles QuitEvent — stops the TUI event loop.
 *
 * Implements TuiListenerRegistrar for DI-driven registration.
 * Fixed: __invoke now accepts QuitEvent so Tui::addListener can
 * infer the event class from the first parameter type hint.
 */
final class QuitListener implements TuiListenerRegistrar
{
    public function register(TuiRuntimeContext $context): void
    {
        $tui = $context->tui;

        $context->tui->addListener(static function (QuitEvent $event) use ($tui): void {
            $tui->stop();
        });
    }
}
