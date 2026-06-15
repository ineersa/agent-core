<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Completion\CompletionContext;
use Ineersa\Tui\Completion\CompletionProvider;
use Ineersa\Tui\Completion\CompletionState;
use Ineersa\Tui\Completion\CompletionSuggestion;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Symfony\Component\Tui\Event\InputEvent;

/**
 * Registers TUI-level input listeners for editor completion.
 *
 * Delegates to a {@see CompletionProvider} (typically a composite)
 * that handles both slash commands and @ file mentions.
 *
 * Completion opens automatically as the user types a leading &quot;/&quot;
 * or &quot;@&quot; and refines suggestions on further keystrokes.  Tab opens
 * the overlay explicitly and accepts the selected suggestion; Enter
 * accepts the suggestion, then submits the now-completed text
 * through the normal SubmitListener path.
 *
 * Input routing (priority 90, below CtrlCInputInterceptor 100 /
 * ModelControlListener 95, above slot handlers 50):
 *  - Tab: open when closed; accept selected when open (single action)
 *  - Enter: accept selected + let event propagate for editor submit
 *  - Escape: close completion without clearing editor
 *  - Up/Down: navigate suggestions (only when menu is open)
 *  - Printing keys: live completion open/refine/close (never stolen)
 *
 * Completion is not implemented as a slot input handler because
 * slot handlers run at priority 50, always return void, and cannot
 * stop propagation.  Completion needs priority 90 and the ability to
 * consume Tab/Escape/Up/Down before the focused EditorWidget or
 * SubmitListener sees them.
 *
 * Does NOT use {@see EditorWidget::onInput()} — that single-slot
 * callback belongs to {@see PromptHistoryListener}.
 *
 * @see CompletionMenu  Overlay lifecycle (below-editor SelectListWidget)
 */
final class CompletionListener implements TuiListenerRegistrar
{
    public function __construct(
        private readonly CompletionProvider $provider,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $state = new CompletionState();
        $provider = $this->provider;
        $screen = $context->screen;
        $editor = $screen->promptEditor();
        $menu = new CompletionMenu($screen->theme());

        // ── Priority 105: close overlay on Ctrl+C / Ctrl+D ──────────
        // Runs BEFORE CtrlCInputInterceptor (100) so the completion
        // overlay is torn down cleanly.  Does NOT stop propagation;
        // CtrlCInputInterceptor still performs clear/quit logic.
        $context->tui->addListener(
            static function (InputEvent $event) use (
                $screen, $menu, $state,
            ): void {
                $data = $event->getData();

                if ("\x03" === $data || "\x04" === $data) {
                    if ($state->isOpen()) {
                        $menu->close($screen);
                        $state->close();
                        $screen->requestRender();
                        // Do NOT stop propagation — let CtrlCInputInterceptor
                        // handle clear/quit.
                    }
                }
            },
            priority: 105,
        );

        // ── Priority 90: completion input routing ───────────────────
        $context->tui->addListener(
            static function (InputEvent $event) use (
                $state, $provider, $screen, $editor, $menu,
            ): void {
                $data = $event->getData();

                // Shift+Tab must pass through to ModelControlListener.
                if ("\x1b[Z" === $data) {
                    return;
                }

                // ── Tab ──────────────────────────────────────────────
                if ("\t" === $data) {
                    // Menu open: accept selected suggestion.
                    if ($state->isOpen()) {
                        $suggestion = $state->acceptSelected();
                        if (null !== $suggestion) {
                            self::applySuggestion($editor, $suggestion);
                        }
                        $menu->close($screen);
                        $state->close();
                        $screen->requestRender();
                        $event->stopPropagation();

                        return;
                    }

                    // Menu closed: query provider, open if suggestions.
                    $text = $editor->getText();
                    $ctx = CompletionContext::forCursorAtEnd($text);
                    $suggestions = $provider->getSuggestions($ctx);

                    if ([] !== $suggestions) {
                        $state->open($suggestions);
                        $menu->open($screen, $state);
                        $screen->requestRender();
                        $event->stopPropagation();

                        return;
                    }

                    // No suggestions — let Tab pass through to editor.
                    return;
                }

                // ── Enter ─────────────────────────────────────────────
                // Menu open: accept selected, close menu, and let Enter
                // propagate to the focused EditorWidget so the completed
                // text is submitted through the normal slash-command path.
                if ("\n" === $data || "\r" === $data) {
                    if ($state->isOpen()) {
                        $suggestion = $state->acceptSelected();
                        if (null !== $suggestion) {
                            self::applySuggestion($editor, $suggestion);
                        }
                        $menu->close($screen);
                        $state->close();
                        $screen->requestRender();
                        // Enter MUST reach the editor so it submits.
                    }

                    return;
                }

                // ── Escape ────────────────────────────────────────────
                if ("\x1b" === $data) {
                    if ($state->isOpen()) {
                        $menu->close($screen);
                        $state->close();
                        $screen->requestRender();
                        $event->stopPropagation();

                        return;
                    }

                    // Not in completion — let Escape pass through.
                    return;
                }

                // ── Up / Down navigation ──────────────────────────────
                $isUp = "\x1b[A" === $data || "\x1bOA" === $data;
                $isDown = "\x1b[B" === $data || "\x1bOB" === $data;

                if ($isUp && $state->isOpen()) {
                    $state->movePrevious();
                    $menu->update($screen, $state);
                    $screen->requestRender();
                    $event->stopPropagation();

                    return;
                }

                if ($isDown && $state->isOpen()) {
                    $state->moveNext();
                    $menu->update($screen, $state);
                    $screen->requestRender();
                    $event->stopPropagation();

                    return;
                }

                // ── Live completion on typing ─────────────────────────
                $predictedText = self::predictNextText($editor->getText(), $data);

                if (null !== $predictedText) {
                    $ctx = CompletionContext::forCursorAtEnd($predictedText);
                    $suggestions = $provider->getSuggestions($ctx);

                    if ([] !== $suggestions) {
                        $state->open($suggestions);
                        $menu->open($screen, $state);
                        $screen->requestRender();

                        return;
                    }

                    // Predicted text no longer has suggestions — close.
                    if ($state->isOpen()) {
                        $menu->close($screen);
                        $state->close();
                        $screen->requestRender();
                    }

                    return;
                }

                // ── Non-predictable input while menu is open ──────────
                if ($state->isOpen()) {
                    $menu->close($screen);
                    $state->close();
                    $screen->requestRender();
                }
            },
            priority: 90,
        );
    }

    // ─── Private helpers ─────────────────────────────────────────────

    /**
     * Predict the editor text after a keystroke is applied.
     *
     * Returns null when the keystroke cannot be modelled with the
     * cursor-at-end heuristic (escape sequences, control chars,
     * etc.), in which case the overlay should be closed if open.
     */
    private static function predictNextText(string $current, string $data): ?string
    {
        $len = \strlen($data);

        if (0 === $len) {
            return null;
        }

        // Escape / CSI sequences — cannot predict
        if ("\x1b" === $data[0] || "\x9b" === $data[0]) {
            return null;
        }

        // Ctrl-letter (including Tab) and other control chars —
        // editor won't insert printable text for these.
        if (1 === $len && \ord($data) < 32) {
            return null;
        }

        // Enter / Return — editor submits, don't predict.
        if ("\n" === $data || "\r" === $data) {
            return null;
        }

        // Backspace / Delete — remove last char (cursor-at-end MVP).
        if ("\x7f" === $data || "\x08" === $data) {
            if ('' === $current) {
                return null;
            }

            // UTF-8 code-point-safe removal of the last character.
            // Sufficient for ASCII slash command contexts; falls back
            // to byte-level substr for resilience.
            $trimmed = preg_replace('/.$/usD', '', $current);
            if (null === $trimmed || $trimmed === $current) {
                return substr($current, 0, -1);
            }

            return $trimmed;
        }

        // Printable character — append.
        return $current.$data;
    }

    /**
     * Apply a completion suggestion to the editor.
     *
     * Uses {@see PromptEditor::acceptCompletion()} which deletes the
     * replacement suffix through the editor's normal Backspace path
     * and inserts the suggestion through the editor's normal
     * character-input path.  This preserves multi-line content,
     * cursor state, and the editor's undo/line-structure invariants
     * — unlike the previous full-text replaceText() approach which
     * was only safe for single-line, cursor-at-start scenarios.
     */
    private static function applySuggestion(
        PromptEditor $editor,
        CompletionSuggestion $suggestion,
    ): void {
        $editor->acceptCompletion($suggestion);
    }
}
