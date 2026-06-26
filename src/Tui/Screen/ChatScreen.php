<?php

declare(strict_types=1);

namespace Ineersa\Tui\Screen;

use Ineersa\CodingAgent\Runtime\Contract\LoadedResourcesSummaryDTO;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Extension\SlotBasedTuiExtensionContext;
use Ineersa\Tui\Extension\TuiExtensionContext;
use Ineersa\Tui\Footer\FooterBarWidget;
use Ineersa\Tui\Footer\FooterDataProvider;
use Ineersa\Tui\Footer\FooterSegment;
use Ineersa\Tui\Footer\FooterSegmentProvider;
use Ineersa\Tui\Header\HeaderWidget;
use Ineersa\Tui\Layout\TuiSlotRegistry;
use Ineersa\Tui\Startup\LoadedResourcesWidget;
use Ineersa\Tui\Status\StatusPanelWidget;
use Ineersa\Tui\Status\WorkingStatusWidget;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Ineersa\Tui\Transcript\PendingMessagesWidget;
use Ineersa\Tui\Transcript\TranscriptBlockWidget;
use Ineersa\Tui\Widget\LiveTextWidget;
use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\WidgetPlacementEnum;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\EditorWidget;

/**
 * Production screen bridge between the TUI layout/widget system and Symfony TUI.
 *
 * ChatScreen owns:
 *  - TuiSlotRegistry and SlotBasedTuiExtensionContext (extension slot model)
 *  - Default renderable TuiWidget instances (HeaderWidget, TranscriptBlockWidget, etc.)
 *  - A PromptEditor facade (DI service) wrapping a real Symfony EditorWidget
 *  - LiveTextWidget adapters that re-render at the live terminal width on every
 *    tick — so separators, header, and footer respond to terminal resize.
 *
 * ChatScreen receives a PromptEditor (DI service wrapping EditorWidget)
 * and provides a clean listener-friendly API so listeners never
 * touch concrete Symfony widget refs directly.
 *
 * ## Resize responsiveness
 *
 * All structural widgets (separators, header, footer, top margin, extension
 * slots) use {@see LiveTextWidget} with a producer closure that reads the
 * current {@see RenderContext} and re-computes content at the live terminal
 * width. Dynamic sections (transcript, working status, status panel) also
 * use {@see LiveTextWidget}; their producer closures read the mutable
 * renderables/registry and re-wrap at the new width when the terminal
 * resizes (render cache miss on changed columns).
 */
final class ChatScreen
{
    /** Number of blank lines rendered before the header logo. */
    private const int TOP_MARGIN_LINES = 4;

    /* ── Symfony widget refs (internal) ── */
    private readonly LiveTextWidget $topMarginWidget;
    private readonly LiveTextWidget $headerWidget;
    private readonly LiveTextWidget $headerSepWidget;
    private readonly LiveTextWidget $loadedResourcesWidget;
    private readonly LiveTextWidget $transcriptWidget;
    private readonly LiveTextWidget $pendingWidget;
    private readonly LiveTextWidget $workingWidget;
    private readonly LiveTextWidget $statusPanelWidget;
    private readonly LiveTextWidget $aboveEditorWidget;
    private readonly LiveTextWidget $editorSepWidget;
    private readonly LiveTextWidget $belowEditorWidget;
    private readonly LiveTextWidget $footerSepWidget;
    private readonly LiveTextWidget $footerWidget;

    /* ── TUI renderables (theme-agnostic, read by producer closures) ── */
    private readonly HeaderWidget $headerRenderable;
    private readonly TranscriptBlockWidget $transcriptRenderable;
    private readonly PendingMessagesWidget $pendingRenderable;
    private readonly WorkingStatusWidget $workingRenderable;
    private readonly StatusPanelWidget $statusPanelRenderable;
    private readonly FooterDataProvider $footerDataProvider;
    private readonly FooterBarWidget $footerRenderable;
    private readonly LoadedResourcesWidget $loadedResourcesRenderable;

    /* ── Slot system ── */
    private readonly TuiSlotRegistry $registry;
    private readonly SlotBasedTuiExtensionContext $extensionContext;

    /* ── Overlay management ── */
    private ?Tui $tui = null;

    /* ── Mount flag ── */
    private bool $mounted = false;

    public function __construct(
        private readonly TuiTheme $theme,
        private string $sessionId,
        private readonly PromptEditor $promptEditor,
    ) {
        $this->registry = new TuiSlotRegistry();

        // ── Instantiate default renderables ──
        $this->headerRenderable = new HeaderWidget();
        $this->transcriptRenderable = new TranscriptBlockWidget();
        $this->pendingRenderable = new PendingMessagesWidget();
        $this->workingRenderable = new WorkingStatusWidget();
        $this->statusPanelRenderable = new StatusPanelWidget();
        $this->footerDataProvider = new FooterDataProvider();
        $this->extensionContext = new SlotBasedTuiExtensionContext($this->registry, $this->footerDataProvider);
        $this->footerDataProvider->setProvider('_default', $this->createDefaultFooterProvider());
        $this->footerRenderable = new FooterBarWidget($this->footerDataProvider);
        $this->loadedResourcesRenderable = new LoadedResourcesWidget();

        // ── Top margin ──
        // Produces TOP_MARGIN_LINES blank lines.  Unlike TextWidget,
        // LiveTextWidget preserves empty lines so the margin renders.
        $this->topMarginWidget = new LiveTextWidget(
            static fn (RenderContext $ctx) => str_repeat("\n", self::TOP_MARGIN_LINES - 1),
        );

        // ═══════════════════════════════════════════════════
        //  Producer closures for responsive widgets
        //
        //  Each closure captures $this and reads from the
        //  renderable/registry on every render.  The Symfony
        //  render cache (keyed on revision × columns × rows)
        //  ensures we only re-compute when dimensions change
        //  or invalidate() is called.
        // ═══════════════════════════════════════════════════

        // ── Header ──
        $this->headerWidget = new LiveTextWidget(
            function (RenderContext $symfonyCtx): string {
                $src = $this->registry->getHeader() ?? $this->headerRenderable;
                $tuiCtx = $this->tuiContext($symfonyCtx);

                return implode("\n", $src->render($tuiCtx));
            },
        );

        // ── Loaded resources (startup block) ──
        $this->loadedResourcesWidget = new LiveTextWidget(
            function (RenderContext $symfonyCtx): string {
                $tuiCtx = $this->tuiContext($symfonyCtx);

                return implode("\n", $this->loadedResourcesRenderable->render($tuiCtx));
            },
        );

        // ── Separator (used for all separator rows) ──
        $this->headerSepWidget = new LiveTextWidget(
            function (RenderContext $symfonyCtx): string {
                return $this->theme->color(
                    ThemeColorEnum::Separator,
                    str_repeat('─', $symfonyCtx->getColumns()),
                );
            },
        );

        // ── Transcript ──
        $this->transcriptWidget = new LiveTextWidget(
            function (RenderContext $symfonyCtx): string {
                $tuiCtx = $this->tuiContext($symfonyCtx);

                return implode("\n", $this->transcriptRenderable->render($tuiCtx));
            },
        );

        // ── Pending messages ──
        $this->pendingWidget = new LiveTextWidget(
            function (RenderContext $symfonyCtx): string {
                $tuiCtx = $this->tuiContext($symfonyCtx);

                return implode("\n", $this->pendingRenderable->render($tuiCtx));
            },
        );

        // ── Working status ──
        $this->workingWidget = new LiveTextWidget(
            function (RenderContext $symfonyCtx): string {
                if (!$this->registry->isWorkingVisible()) {
                    return '';
                }
                $msg = $this->registry->getWorkingMessage();
                $this->workingRenderable->setMessage($msg);
                $this->workingRenderable->setVisible(true);
                $tuiCtx = $this->tuiContext($symfonyCtx);

                return implode("\n", $this->workingRenderable->render($tuiCtx));
            },
        );

        // ── Status panel ──
        $this->statusPanelWidget = new LiveTextWidget(
            function (RenderContext $symfonyCtx): string {
                $entries = $this->registry->getStatusEntries();
                $this->statusPanelRenderable->setEntries($entries);
                $tuiCtx = $this->tuiContext($symfonyCtx);

                return implode("\n", $this->statusPanelRenderable->render($tuiCtx));
            },
        );

        // ── Extension widgets: above editor ──
        $this->aboveEditorWidget = new LiveTextWidget(
            function (RenderContext $symfonyCtx): string {
                $tuiCtx = $this->tuiContext($symfonyCtx);
                $lines = [];
                foreach ($this->registry->getWidgetsByPlacement(WidgetPlacementEnum::AboveEditor) as $widget) {
                    $lines = array_merge($lines, $widget->render($tuiCtx));
                }

                return implode("\n", $lines);
            },
        );

        // ── Editor separator ──
        $this->editorSepWidget = new LiveTextWidget(
            function (RenderContext $symfonyCtx): string {
                return $this->theme->color(
                    ThemeColorEnum::Separator,
                    str_repeat('─', $symfonyCtx->getColumns()),
                );
            },
        );

        // ── Interactive editor (via PromptEditor facade over Symfony EditorWidget) ──
        $this->promptEditor->setMinVisibleLines(1)->setMaxVisibleLines(10);

        // ── Extension widgets: below editor ──
        $this->belowEditorWidget = new LiveTextWidget(
            function (RenderContext $symfonyCtx): string {
                $tuiCtx = $this->tuiContext($symfonyCtx);
                $lines = [];
                foreach ($this->registry->getWidgetsByPlacement(WidgetPlacementEnum::BelowEditor) as $widget) {
                    $lines = array_merge($lines, $widget->render($tuiCtx));
                }

                return implode("\n", $lines);
            },
        );

        // ── Footer separator ──
        $this->footerSepWidget = new LiveTextWidget(
            function (RenderContext $symfonyCtx): string {
                return $this->theme->color(
                    ThemeColorEnum::Separator,
                    str_repeat('─', $symfonyCtx->getColumns()),
                );
            },
        );

        // ── Footer ──
        $this->footerWidget = new LiveTextWidget(
            function (RenderContext $symfonyCtx): string {
                $src = $this->registry->getFooter() ?? $this->footerRenderable;
                $tuiCtx = $this->tuiContext($symfonyCtx);

                return implode("\n", $src->render($tuiCtx));
            },
            truncate: true,
        );
    }

    /* ────────── Mounting ────────── */

    /**
     * Add all widgets to the Tui and perform initial render.
     *
     * Must be called exactly once before listeners interact with the screen.
     */
    public function mount(Tui $tui): void
    {
        if ($this->mounted) {
            return;
        }
        $this->mounted = true;
        $this->tui = $tui;

        // Add widgets in display order (top → bottom).
        // LiveTextWidget producers already read live RenderContext columns,
        // so no cached terminalWidth is needed.
        $tui->add($this->topMarginWidget);
        $tui->add($this->headerWidget);
        $tui->add($this->headerSepWidget);
        $tui->add($this->loadedResourcesWidget);
        $tui->add($this->transcriptWidget);
        $tui->add($this->pendingWidget);
        $tui->add($this->workingWidget);
        $tui->add($this->statusPanelWidget);
        $tui->add($this->aboveEditorWidget);
        $tui->add($this->editorSepWidget);
        $tui->add($this->promptEditor->getWidget());

        // The PromptEditor wraps an EditorWidget that is a DI singleton
        // reused across TUI iterations during session switches.  After
        // the old TUI stopped, the widget's render cache still holds
        // lines computed for the old TUI's dimensions and stylesheet.
        // Invalidate it here so the first render in the new TUI
        // re-computes the frame/border and editor content correctly.
        $this->promptEditor->getWidget()->invalidate();

        $tui->add($this->belowEditorWidget);
        $tui->add($this->footerSepWidget);
        $tui->add($this->footerWidget);
    }

    /* ────────── Public API (listener-friendly) ────────── */

    /**
     * Expose the active theme for listeners/controllers that need theme-aware
     * rendering (e.g. picker controllers colouring labels or headers).
     */
    public function theme(): TuiTheme
    {
        return $this->theme;
    }

    public function editorWidget(): EditorWidget
    {
        return $this->promptEditor->getWidget();
    }

    public function promptEditor(): PromptEditor
    {
        return $this->promptEditor;
    }

    public function clearEditor(): void
    {
        $this->promptEditor->clear();
    }

    public function editorText(): string
    {
        return $this->promptEditor->getText();
    }

    /**
     * Extract the current editor text and clear the editor.
     *
     * Convenience method that delegates to {@see PromptEditor::extract()}.
     * Use this in SubmitListener instead of getValue() + clearEditor().
     */
    public function extract(): string
    {
        return $this->promptEditor->extract();
    }

    public function setLoadedResourcesSummary(?LoadedResourcesSummaryDTO $summary): void
    {
        $this->loadedResourcesRenderable->setSummary($summary);
        $this->loadedResourcesWidget->invalidate();
    }

    public function hasLoadedResourcesBlock(): bool
    {
        return $this->loadedResourcesRenderable->hasContent();
    }

    public function toggleLoadedResourcesExpanded(): void
    {
        $this->loadedResourcesRenderable->toggleExpanded();
        $this->loadedResourcesWidget->invalidate();
    }

    /** @param array<int, object> $blocks */
    public function setTranscriptBlocks(array $blocks): void
    {
        $this->transcriptRenderable->setBlocks($blocks);
        $this->transcriptWidget->invalidate();
    }

    /**
     * Sync the pending-queue widget (layout slot 4, above the editor) with the
     * current queued steer/follow-up messages from TuiSessionState.
     *
     * Called every render tick by TickPollListener. Entries render as muted
     * "⏳ <text>" lines until the canonical user message is applied to the run,
     * at which point the entry is popped and the ❯ user message is appended to
     * the transcript.
     *
     * @param array<string, string> $queuedMessages idempotency_key => text
     */
    public function syncQueuedUserMessages(array $queuedMessages): void
    {
        $this->pendingRenderable->setMessages(array_values($queuedMessages));
        $this->pendingWidget->invalidate();
    }

    public function setWorkingMessage(?string $message): void
    {
        $this->registry->setWorkingMessage($message);
        $this->workingRenderable->setMessage($message);
        $this->workingWidget->invalidate();
    }

    public function setWorkingVisible(bool $visible): void
    {
        $this->registry->setWorkingVisible($visible);
        $this->workingRenderable->setVisible($visible);
        $this->workingWidget->invalidate();
    }

    public function setStatus(string $key, ?string $text): void
    {
        $this->registry->setStatus($key, $text);
        $this->statusPanelRenderable->setEntry($key, $text);
        $this->footerDataProvider->setStatus($key, $text);
        $this->statusPanelWidget->invalidate();
        $this->footerWidget->invalidate();
    }

    /**
     * Apply the editor border colour matching the current reasoning level.
     *
     * Maps reasoning levels (off, minimal, low, medium, high, xhigh) to the
     * corresponding theme thinking-colour tokens and updates the Symfony TUI
     * stylesheet so the EditorWidget frame is rendered in that colour.
     *
     * Uses {@see FooterStateSegmentProvider::thinkingColor()} so the
     * mapping stays consistent between the footer diamond/model and the
     * editor border.
     */
    public function applyEditorBorderColor(string $reasoning): void
    {
        if (null === $this->tui) {
            return;
        }

        $colorEnum = ThemeColorEnum::forReasoning($reasoning);
        $colorSpec = $this->theme->getPalette()->get($colorEnum);

        $style = new Style(color: $colorSpec);
        $this->tui->addStyleSheet(new StyleSheet([
            EditorWidget::class.'::frame' => $style,
        ]));

        // Invalidate the editor widget so its cached render (which
        // used the old stylesheet) is discarded and the frame is
        // repainted with the new colour.
        $this->promptEditor->getWidget()->invalidate();
        $this->tui->requestRender();
    }

    /**
     * Register an additional footer segment provider.
     *
     * Providers are invoked on every footer render. Use this to add
     * live data segments such as model, token usage, or elapsed time.
     */
    public function addFooterProvider(FooterSegmentProvider $provider): void
    {
        $this->footerDataProvider->addProvider($provider);
        $this->footerWidget->invalidate();
    }

    /**
     * Invalidate all mutable widgets so they re-render on the next tick.
     *
     * Extension widgets and status entries change via {@see setStatus()} or
     * extension calls and already invalidate targeted widgets.  This method
     * is a safety net for external state changes.
     */
    public function refresh(): void
    {
        $this->transcriptWidget->invalidate();
        $this->pendingWidget->invalidate();
        $this->workingWidget->invalidate();
        $this->statusPanelWidget->invalidate();
        $this->aboveEditorWidget->invalidate();
        $this->belowEditorWidget->invalidate();
        $this->footerWidget->invalidate();
        // Invalidate the editor widget so broad screen refreshes (startup,
        // model change) also re-render the editor frame with current styles.
        $this->promptEditor->getWidget()->invalidate();
    }

    /* ────────── Overlay management ────────── */

    /**
     * Insert an interactive overlay widget above the editor.
     *
     * The Symfony TUI root container renders children in append order.
     * This method removes the editor and all widgets below it, adds the
     * overlay, then re-adds everything in original order so the overlay
     * renders directly above the editor area:
     *
     *   … → status → aboveEditorWidget → overlay → editorSep → editor →
     *   belowEditorWidget → footerSep → footer
     *
     * @throws \LogicException when the screen has not been mounted yet
     */
    public function insertOverlayBeforeEditor(AbstractWidget $widget): void
    {
        if (null === $this->tui) {
            throw new \LogicException('insertOverlayBeforeEditor() requires ChatScreen to be mounted first. Call mount() before inserting overlays.');
        }

        // Remove editor area and everything below it (reverse mount order).
        $this->tui->remove($this->footerWidget);
        $this->tui->remove($this->footerSepWidget);
        $this->tui->remove($this->belowEditorWidget);
        $this->tui->remove($this->promptEditor->getWidget());
        $this->tui->remove($this->editorSepWidget);

        // Add the overlay (appended after aboveEditorWidget).
        $this->tui->add($widget);

        // Restore editor area widgets in original mount order.
        $this->tui->add($this->editorSepWidget);
        $this->tui->add($this->promptEditor->getWidget());
        $this->tui->add($this->belowEditorWidget);
        $this->tui->add($this->footerSepWidget);
        $this->tui->add($this->footerWidget);
    }

    /**
     * Insert an interactive overlay widget below the editor.
     *
     * Removes the below-editor area and footer, adds the overlay, then
     * restores everything in original order so the overlay renders
     * directly below the editor:
     *
     *   … → editor → overlay → belowEditorWidget → footerSep → footer
     *
     * Used by completion menus that should appear below the prompt
     * while the editor keeps focus (unlike question/picker overlays
     * which steal focus and render above the editor).
     *
     * @throws \LogicException when the screen has not been mounted yet
     */
    public function insertOverlayAfterEditor(AbstractWidget $widget): void
    {
        if (null === $this->tui) {
            throw new \LogicException('insertOverlayAfterEditor() requires ChatScreen to be mounted first. Call mount() before inserting overlays.');
        }

        // Remove everything below the editor (reverse mount order).
        $this->tui->remove($this->footerWidget);
        $this->tui->remove($this->footerSepWidget);
        $this->tui->remove($this->belowEditorWidget);

        // Add the overlay (appended after the editor).
        $this->tui->add($widget);

        // Restore below-editor widgets in original mount order.
        $this->tui->add($this->belowEditorWidget);
        $this->tui->add($this->footerSepWidget);
        $this->tui->add($this->footerWidget);
    }

    /**
     * Remove an overlay widget from the TUI root.
     *
     * Companion to {@see insertOverlayBeforeEditor()} and
     * {@see insertOverlayAfterEditor()}. Safe to call before
     * mount — it is a no-op when the screen has not been mounted yet.
     */
    public function removeOverlay(AbstractWidget $widget): void
    {
        $this->tui?->remove($widget);
    }

    /**
     * Set keyboard focus on a TUI widget.
     */
    public function setFocus(AbstractWidget $widget): void
    {
        $this->tui?->setFocus($widget);
    }

    /**
     * Request a render on the next tick.
     *
     * @param bool $force When true, bypasses the dirty-tracking render cache
     */
    public function requestRender(bool $force = false): void
    {
        $this->tui?->requestRender($force);
    }

    /* ────────── Slot access ────────── */

    public function registry(): TuiSlotRegistry
    {
        return $this->registry;
    }

    public function extensionContext(): TuiExtensionContext
    {
        return $this->extensionContext;
    }

    /**
     * Update the session ID displayed in the default footer segment.
     *
     * Used by SubmitListener when a draft session (empty ID) is
     * promoted to a real session on the first submitted message.
     */
    public function updateSessionId(string $sessionId): void
    {
        $this->sessionId = $sessionId;
        $this->footerDataProvider->setProvider('_default', $this->createDefaultFooterProvider());
    }

    /* ────────── Helpers ────────── */

    /**
     * Build a TuiRenderContext from a Symfony RenderContext.
     *
     * Uses the live terminal columns from Symfony's render pipeline,
     * so TuiWidgets always render at the current terminal width.
     */
    private function tuiContext(RenderContext $symfonyCtx): TuiRenderContext
    {
        return new TuiRenderContext(
            terminalWidth: $symfonyCtx->getColumns(),
            theme: $this->theme,
        );
    }

    private function createDefaultFooterProvider(): FooterSegmentProvider
    {
        $sessionId = $this->sessionId;

        return new readonly class($sessionId) implements FooterSegmentProvider {
            public function __construct(
                private string $sessionId,
            ) {
            }

            /** @return list<FooterSegment> */
            public function getSegments(): array
            {
                if ('' === $this->sessionId) {
                    return [];
                }

                return [
                    new FooterSegment(
                        text: 'session '.$this->sessionId,
                        priority: 110,
                    ),
                ];
            }
        };
    }
}
