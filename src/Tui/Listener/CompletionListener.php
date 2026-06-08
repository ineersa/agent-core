<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Completion\CompletionContext;
use Ineersa\Tui\Completion\CompletionState;
use Ineersa\Tui\Completion\CompletionSuggestion;
use Ineersa\Tui\Completion\SlashCommandCompletionProvider;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Registers TUI-level input listeners for slash command completion.
 *
 * Completion opens automatically as the user types a leading "/"
 * (or after a newline at column 0) and refines suggestions on further
 * keystrokes.  Tab opens the overlay explicitly and accepts the
 * selected suggestion.
 *
 * Input routing:
 *  - Typing: opens/refines completion when predicted text has slash context
 *  - Tab: open completion when closed; accept selected when open
 *  - Escape: close completion without clearing editor text
 *  - Up/Down: navigate suggestions (only when menu is open)
 *  - Normal non-slash typing or other input: closes stale menu, passes through
 *
 * Uses a TUI-level {@see InputEvent} listener at priority 90, below
 * {@see CtrlCInputInterceptor} (100) and {@see ModelControlListener}
 * (95), above slot handlers (50) and focused editor input.
 *
 * Does NOT use {@see EditorWidget::onInput()} — that single-slot
 * callback belongs to {@see PromptHistoryListener}.
 *
 * Implements TuiListenerRegistrar for DI-driven registration.
 */
final class CompletionListener implements TuiListenerRegistrar
{
    public function __construct(
        private readonly SlashCommandCompletionProvider $slashProvider,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $state = new CompletionState();
        $provider = $this->slashProvider;
        $screen = $context->screen;
        $editor = $screen->promptEditor();
        $theme = $screen->theme();

        // Mutable overlay state captured by the closure (by reference).
        /** @var ?ContainerWidget $overlayWidget */
        $overlayWidget = null;

        // ── Priority 105: close overlay on Ctrl+C / Ctrl+D ──────────
        // Runs BEFORE CtrlCInputInterceptor (100) so the completion
        // overlay is torn down cleanly.  Does NOT stop propagation;
        // CtrlCInputInterceptor still performs clear/quit logic.
        $context->tui->addListener(
            static function (InputEvent $event) use (
                $screen, &$overlayWidget, $state,
            ): void {
                $data = $event->getData();

                if ("\x03" === $data || "\x04" === $data) {
                    if ($state->isOpen()) {
                        self::closeOverlay($screen, $overlayWidget);
                        $state->close();
                        $screen->requestRender();
                        // Do NOT stop propagation — let CtrlCInputInterceptor
                        // and other listeners handle the clear/quit logic.
                    }
                }
            },
            priority: 105,
        );

        // ── Priority 90: completion Tab / Escape / Up/Down ──────────
        $context->tui->addListener(
            static function (InputEvent $event) use (
                $state, $provider, $screen, $editor, $theme, &$overlayWidget,
            ): void {
                $data = $event->getData();

                // Shift+Tab must pass through to ModelControlListener.
                if ("\x1b[Z" === $data) {
                    return;
                }

                // ── Tab ──────────────────────────────────────────────────
                if ("\t" === $data) {
                    // Menu open: accept selected suggestion.
                    if ($state->isOpen()) {
                        $suggestion = $state->acceptSelected();
                        if (null !== $suggestion) {
                            self::applySuggestion($editor, $suggestion);
                        }
                        self::closeOverlay($screen, $overlayWidget);
                        $state->close();
                        $screen->requestRender();
                        $event->stopPropagation();

                        return;
                    }

                    // Menu closed: query provider and open if suggestions found.
                    $text = $editor->getText();
                    $ctx = CompletionContext::forCursorAtEnd($text);
                    $suggestions = $provider->getSuggestions($ctx);

                    if ([] !== $suggestions) {
                        $state->open($suggestions);
                        self::closeOverlay($screen, $overlayWidget);
                        $overlayWidget = self::renderOverlay($screen, $theme, $state);
                        $screen->requestRender();
                        $event->stopPropagation();

                        return;
                    }

                    // No suggestions — let Tab pass through to editor.
                    return;
                }

                // ── Escape ───────────────────────────────────────────────
                if ("\x1b" === $data) {
                    if ($state->isOpen()) {
                        self::closeOverlay($screen, $overlayWidget);
                        $state->close();
                        $screen->requestRender();
                        $event->stopPropagation();

                        return;
                    }

                    // Not in completion — let Escape pass through to editor/CancelListener.
                    return;
                }

                // ── Up / Down navigation ─────────────────────────────────
                $isUp = "\x1b[A" === $data || "\x1bOA" === $data;
                $isDown = "\x1b[B" === $data || "\x1bOB" === $data;

                if ($isUp && $state->isOpen()) {
                    $state->movePrevious();
                    self::closeOverlay($screen, $overlayWidget);
                    $overlayWidget = self::renderOverlay($screen, $theme, $state);
                    $screen->requestRender();
                    $event->stopPropagation();

                    return;
                }

                if ($isDown && $state->isOpen()) {
                    $state->moveNext();
                    self::closeOverlay($screen, $overlayWidget);
                    $overlayWidget = self::renderOverlay($screen, $theme, $state);
                    $screen->requestRender();
                    $event->stopPropagation();

                    return;
                }

                // ── Live completion on typing ────────────────────────────
                // Predict the editor state after the key reaches the
                // editor, then open/refine or close the overlay accordingly.
                // Never stop propagation — the editor MUST receive the key.
                $predictedText = self::predictNextText($editor->getText(), $data);

                if (null !== $predictedText) {
                    $ctx = CompletionContext::forCursorAtEnd($predictedText);
                    $suggestions = $provider->getSuggestions($ctx);

                    if ([] !== $suggestions) {
                        // Open or refine the completion overlay.
                        $state->open($suggestions);
                        self::closeOverlay($screen, $overlayWidget);
                        $overlayWidget = self::renderOverlay($screen, $theme, $state);
                        $screen->requestRender();

                        return;
                    }

                    // Predicted text no longer has suggestions.
                    if ($state->isOpen()) {
                        self::closeOverlay($screen, $overlayWidget);
                        $state->close();
                        $screen->requestRender();
                    }

                    return;
                }

                // ── Non-predictable input while menu is open ───────────────
                // Inputs predictNextText cannot model (remaining escape
                // sequences, CSI codes, or unrecognised control chars) —
                // close stale menu.
                if ($state->isOpen()) {
                    self::closeOverlay($screen, $overlayWidget);
                    $state->close();
                    $screen->requestRender();
                }
            },
            priority: 90,
        );
    }

    // ─── Private helpers ─────────────────────────────────────────────────

    /**
     * Render the completion menu overlay and insert it above the editor.
     *
     * Creates a {@see ContainerWidget} with {@see TextWidget} items for each
     * suggestion.  The selected item is prefixed with "› " and rendered
     * in accent colour; descriptions are rendered in muted colour.
     *
     * The overlay is non-focused: editor focus is preserved so typing
     * continues normally.  Navigation is handled by the InputEvent listener.
     *
     * Caller is responsible for calling {@see closeOverlay} before this
     * method to avoid widget accumulation.
     *
     * @return ContainerWidget the created overlay widget
     */
    private static function renderOverlay(
        ChatScreen $screen,
        TuiTheme $theme,
        CompletionState $state,
    ): ContainerWidget {
        $container = new ContainerWidget();
        $suggestions = $state->getSuggestions();
        $selectedIndex = $state->getSelectedIndex();

        foreach ($suggestions as $i => $suggestion) {
            $isSelected = $i === $selectedIndex;
            $prefix = $isSelected ? '› ' : '  ';
            $label = $theme->accent($prefix.$suggestion->display);

            if ('' !== $suggestion->description) {
                $label .= '  '.$theme->muted($suggestion->description);
            }

            $container->add(new TextWidget($label));
        }

        $screen->insertOverlayBeforeEditor($container);

        return $container;
    }

    /**
     * Remove the completion overlay from the screen.
     *
     * @param ContainerWidget|null $overlayWidget reference to the mutable overlay
     *
     * @param-out null             $overlayWidget
     */
    private static function closeOverlay(
        ChatScreen $screen,
        ?ContainerWidget &$overlayWidget,
    ): void {
        if (null !== $overlayWidget) {
            $screen->removeOverlay($overlayWidget);
            $overlayWidget = null;
        }
    }

    /**
     * Predict the editor text after a keystroke is applied.
     *
     * Returns null when the keystroke cannot be modelled with the
     * cursor-at-end heuristic (escape sequences, control chars,
     * etc.), in which case the overlay should be closed if open.
     *
     * The editor cursor is always at the end of text in the
     * EDITOR-08 MVP; future phases with real cursor tracking
     * should update this to splice at the cursor position.
     */
    private static function predictNextText(string $current, string $data): ?string
    {
        $len = \strlen($data);

        if (0 === $len) {
            return null;
        }

        // Escape / CSI sequences — cannot predict
        // (also catches Shift+Tab / \x1b[Z).
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
        // \x7f is DEL (Linux/macOS), \x08 is BS/Ctrl+H (some terminals).
        if ("\x7f" === $data || "\x08" === $data) {
            if ('' === $current) {
                return null;
            }

            // Use preg_replace for UTF-8 code-point-safe removal of the
            // last character. Sufficient for ASCII slash command contexts;
            // falls back to byte-level substr for resilience.
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
     * Replaces the slash prefix range with the suggestion's insert text.
     * Uses {@see PromptEditor::setText()} which resets the cursor to
     * line 0/col 0 — a known tradeoff accepted for EDITOR-08.
     */
    private static function applySuggestion(
        PromptEditor $editor,
        CompletionSuggestion $suggestion,
    ): void {
        $current = $editor->getText();
        $new = substr_replace(
            $current,
            $suggestion->insertText,
            $suggestion->replacementStart,
            $suggestion->replacementLength,
        );
        $editor->setText($new);
    }
}
