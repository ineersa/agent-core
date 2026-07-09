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
 *  - EditorWidget::onInput() is single-slot (only one callback can be
 *    installed; calling it again replaces the previous handler).
 *    EDITOR-08 completions or any future feature that also needs raw
 *    editor input interception MUST compose with this callback — either
 *    by introducing a composite/dispatch handler that calls all registered
 *    handlers in order, or by merging both behaviours into one closure.
 *  - Uses the editor widget's own {@see Keybindings::matches()} so the
 *    same terminal escape sequences that the editor itself recognizes
 *    for cursor_up / cursor_down are used, avoiding raw-escape fragility.
 *  - History is served from a session-scoped {@see PromptHistory} prompt
 *    list, seeded from {@see TuiSessionState::$transcript} once on
 *    {@see register()} (UserMessage blocks) and grown via {@see SubmitListener}
 *    on each real submit. PromptHistory holds a single integer navigation
 *    cursor into its prompt list — no per-keypress transcript scan.
 *  - Down past the newest prompt clears the editor and exits navigation
 *    (shell-like behaviour).
 *  - Any non-Up/Down input exits history navigation mode immediately
 *    and lets the editor handle the key normally.
 *
 * Implements TuiListenerRegistrar for DI-driven registration.
 */
final class PromptHistoryListener implements TuiListenerRegistrar
{
    public function __construct(
        private readonly PromptHistory $history,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $state = $context->state;
        $editor = $context->screen->editorWidget();
        $screen = $context->screen;

        // Runs once per session iteration (start/resume/switch) via register(), before $tui->run() — not per render tick or per submit.
        $this->history->seedFrom($state->transcript);

        $history = $this->history;

        $editor->onInput(static function (string $data) use ($state, $editor, $screen, $history): bool {
            if ($state->subagentLiveView->active) {
                if ($history->isNavigating()) {
                    $history->exitNavigation();
                }

                return false;
            }

            $kb = $editor->getKeybindings();

            $isUp = $kb->matches($data, 'cursor_up');
            $isDown = $kb->matches($data, 'cursor_down');

            // ── Up ──
            if ($isUp) {
                // Only intercept when the editor is empty OR already navigating.
                if ('' === $editor->getText() || $history->isNavigating()) {
                    $text = $history->previous();
                    if (null !== $text) {
                        $editor->setText($text);
                        $screen->requestRender();

                        return true; // Consume the event
                    }

                    // At the oldest prompt while navigating — consume the
                    // event as a no-op instead of letting the editor handle
                    // cursor_up.  This prevents cursor movement within any
                    // recalled multiline text when there is no older history.
                    if ($history->isNavigating()) {
                        return true;
                    }
                }

                // Not intercepting — let the editor handle cursor_up normally.
                return false;
            }

            // ── Down ──
            if ($isDown) {
                if ($history->isNavigating()) {
                    $text = $history->next();
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
            if ($history->isNavigating()) {
                $history->exitNavigation();
            }

            // Let the editor handle the key normally.
            return false;
        });
    }
}
