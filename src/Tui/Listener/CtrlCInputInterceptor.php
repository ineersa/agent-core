<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Layout\InputPriority;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * Intercepts Ctrl+D (quit) and Ctrl+C (cancel / double-press quit).
 *
 * Registered at priority {@see InputPriority::GLOBAL_INTERRUPT} so it runs before other input handlers.
 *
 * Matching uses Symfony TUI {@see Keybindings} so both legacy control bytes
 * (`\x03` / `\x04`) and Kitty CSI-u sequences (`ESC[99;5u` / `ESC[100;5u`) work.
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

        // Mutable state captured by the closure (scoped to this TUI session).
        // The by-reference capture is REQUIRED: PHP re-initialises by-value
        // use() captures on every closure invocation, so the double-press
        // timer would never see the previous press without it.
        $ctrlCLast = 0.0;
        $keys = new Keybindings([
            'quit' => [Key::ctrl('d')],
            'interrupt' => [Key::ctrl('c')],
        ]);

        $context->tui->addListener(
            static function (InputEvent $event) use ($tui, $screen, $keys, &$ctrlCLast): void {
                $data = $event->getData();

                // Ctrl+D → quit
                if ($keys->matches($data, 'quit')) {
                    $event->stopPropagation();
                    $tui->stop();

                    return;
                }

                // Ctrl+C → cancel or double-press quit
                if ($keys->matches($data, 'interrupt')) {
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
            priority: InputPriority::GLOBAL_INTERRUPT,
        );
    }
}
