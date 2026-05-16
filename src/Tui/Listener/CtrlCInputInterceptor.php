<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Symfony\Component\Tui\Event\InputEvent;

/**
 * Intercepts Ctrl+D (quit) and Ctrl+C (cancel / double-press quit).
 *
 * Registered at priority 100 so it runs before other input handlers.
 *
 * Ctrl+D → immediate quit
 * Ctrl+C (with editor text) → clear editor
 * Ctrl+C (empty editor) → show "Press Ctrl+C again to exit"
 * Ctrl+C × 2 within 1.5s → quit
 * Any other key → reset double-press timer
 *
 * Implements TuiListenerRegistrar for DI-driven registration.
 */
final class CtrlCInputInterceptor implements TuiListenerRegistrar
{
    public function register(TuiRuntimeContext $context): void
    {
        $tui = $context->tui;
        $screen = $context->screen;

        // Mutable state captured by the closure (scoped to this TUI session)
        $ctrlCLast = 0.0;

        $context->tui->addListener(
            static function (InputEvent $event) use ($tui, $screen, &$ctrlCLast): void {
                $data = $event->getData();

                // Ctrl+D → quit
                if ("\x04" === $data) {
                    $event->stopPropagation();
                    $tui->stop();

                    return;
                }

                // Ctrl+C → cancel or double-press quit
                if ("\x03" === $data) {
                    $event->stopPropagation();

                    $now = microtime(true);
                    if ($ctrlCLast > 0.0 && ($now - $ctrlCLast) < 1.5) {
                        $tui->stop();

                        return;
                    }

                    if ('' !== $screen->editorText()) {
                        $screen->clearEditor();
                        $screen->setStatus('ctrl_c', null);
                    } else {
                        $screen->setStatus('ctrl_c', 'Press Ctrl+C again to exit');
                    }

                    $ctrlCLast = $now;

                    return;
                }

                // Any other key resets the double-press timer
                if ($ctrlCLast > 0.0) {
                    $ctrlCLast = 0.0;
                    $screen->setStatus('ctrl_c', null);
                }
            },
            priority: 100,
        );
    }
}
