<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Installs a prompt-history input handler on the editor widget.
 *
 * Uses Symfony EditorWidget::onInput() to intercept Up/Down cursor keys
 * before the editor's own cursor-movement handling.  History navigation
 * is active when the editor is empty OR the navigator is already in
 * history mode.
 *
 * Design decisions:
 *  - Single composite callback on onInput() — leaves the slot open for
 *    future EDITOR-08 completion to register via an on-input chain pattern.
 *  - Uses the editor widget's own {@see Keybindings::matches()} so the
 *    same terminal escape sequences that the editor itself recognizes
 *    for cursor_up / cursor_down are used, avoiding raw-escape fragility.
 *  - The navigator scans {@see TuiSessionState::$transcript} on every
 *    call instead of holding a separate prompt-string list.  Memory
 *    overhead is one integer cursor, not duplicated text.
 *  - Down past the newest prompt clears the editor and exits navigation
 *    (shell-like behaviour).
 *  - Any non-Up/Down input exits history navigation mode immediately
 *    and lets the editor handle the key normally.
 *
 * Implements TuiListenerRegistrar for DI-driven registration.
 */
final class PromptHistoryListener implements TuiListenerRegistrar
{
    public function register(TuiRuntimeContext $context): void
    {
        $state = $context->state;
        $editor = $context->screen->editorWidget();
        $screen = $context->screen;

        $navigator = new PromptHistoryNavigator();

        $editor->onInput(static function (string $data) use ($state, $editor, $screen, $navigator): bool {
            $kb = $editor->getKeybindings();

            $isUp = $kb->matches($data, 'cursor_up');
            $isDown = $kb->matches($data, 'cursor_down');

            // ── Up ──
            if ($isUp) {
                // Only intercept when the editor is empty OR already navigating.
                if ('' === $editor->getText() || $navigator->isNavigating()) {
                    $text = $navigator->previous($state->transcript);
                    if (null !== $text) {
                        $editor->setText($text);
                        $screen->requestRender();

                        return true; // Consume the event
                    }
                }

                // Not intercepting — let the editor handle cursor_up normally.
                return false;
            }

            // ── Down ──
            if ($isDown) {
                if ($navigator->isNavigating()) {
                    $text = $navigator->next($state->transcript);
                    if (null !== $text) {
                        $editor->setText($text);
                        $screen->requestRender();

                        return true;
                    }

                    // Past newest — clear editor and exit navigation.
                    $editor->setText('');
                    $screen->requestRender();

                    return true;
                }

                // Not navigating — let the editor handle cursor_down normally.
                return false;
            }

            // ── Any other input exits history navigation ──
            if ($navigator->isNavigating()) {
                $navigator->exitNavigation();
            }

            // Let the editor handle the key normally.
            return false;
        });
    }
}
