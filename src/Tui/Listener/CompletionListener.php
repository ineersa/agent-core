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
 * Completion is triggered by Tab when the current editor text contains
 * a slash command context (text starting with "/" or after a newline
 * at column 0).  A non-focused overlay menu is rendered above the editor.
 *
 * Input routing:
 *  - Tab: open completion when closed; accept selected when open
 *  - Escape: close completion without clearing editor text
 *  - Up/Down: navigate suggestions (only when menu is open)
 *  - Normal typing: closes stale menu, passes through
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

                // ── Normal input while menu is open ──────────────────────
                // Close stale menu and let input through to editor.
                if ($state->isOpen()) {
                    self::closeOverlay($screen, $overlayWidget);
                    $state->close();

                    // Still request render so dropped overlay is painted,
                    // but do NOT stop propagation — editor must handle the key.
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
