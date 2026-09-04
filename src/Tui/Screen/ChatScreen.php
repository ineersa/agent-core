<?php

declare(strict_types=1);

namespace Ineersa\Tui\Screen;

use Ineersa\CodingAgent\Runtime\Contract\LoadedResourcesSummaryDTO;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet;
use Ineersa\Tui\CompactHeader\CompactHeaderWidget;
use Ineersa\Tui\Editor\AppShortcutKeybindings;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Footer\FooterBarWidget;
use Ineersa\Tui\Footer\FooterDataProvider;
use Ineersa\Tui\Footer\FooterSegment;
use Ineersa\Tui\Footer\FooterSegmentProvider;
use Ineersa\Tui\Header\HeaderWidget;
use Ineersa\Tui\Startup\LoadedResourcesWidget;
use Ineersa\Tui\Status\StatusPanelWidget;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Ineersa\Tui\Transcript\PendingMessagesWidget;
use Ineersa\Tui\Transcript\ThemeStyleSheetFactory;
use Ineersa\Tui\Transcript\TranscriptDisplayConfig;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use Ineersa\Tui\Transcript\TranscriptMountedWidget;
use Ineersa\Tui\Widget\LiveTextWidget;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\EditorWidget;
use Symfony\Component\Tui\Widget\LoaderWidget;

/**
 * Production screen bridge between the TUI layout/widget system and Symfony TUI.
 *
 * ChatScreen owns:
 *  - Status entries + working message/visibility state (sole owner/writer; syncs the
 *    native StatusPanelWidget/LoaderWidget directly)
 *  - Directly mounted native Symfony widgets for the chrome regions: header,
 *    loaded resources, transcript, pending, working loader, status panel,
 *    compact header, prompt editor, footer.
 *  - A PromptEditor facade (DI service) wrapping a real Symfony EditorWidget
 *  - LiveTextWidget ONLY for the blank top margin and the three responsive
 *    separator rows: Symfony TextWidget drops whitespace-only text and no
 *    native scrollback/separator primitive is exact for those (bounded KEEP).
 *  - A first-class mounted Symfony transcript subtree ({@see TranscriptMountedWidget})
 *
 * ChatScreen receives a PromptEditor (DI service wrapping EditorWidget)
 * and provides a clean listener-friendly API so listeners never
 * touch concrete Symfony widget refs directly.
 *
 * ## Resize responsiveness
 *
 * The native chrome widgets render through the Symfony widget tree with the
 * live RenderContext, so terminal resize reflows them automatically.
 * Working status is a single mounted native {@see LoaderWidget} (circle spinner)
 * driven through start/stop + finished-indicator states. The transcript is a
 * mounted Symfony container whose children re-render through the live widget
 * tree (including MarkdownWidget sub-element styles from the active stylesheet).
 */
final class ChatScreen
{
    /** Number of blank lines rendered before the header logo. */
    private const int TOP_MARGIN_LINES = 4;

    /* ── Native Symfony chrome widgets (directly mounted) ── */
    private readonly LiveTextWidget $topMarginWidget;
    private readonly HeaderWidget $headerWidget;
    private readonly LiveTextWidget $headerSepWidget;
    private readonly LoadedResourcesWidget $loadedResourcesWidget;
    private readonly TranscriptMountedWidget $transcriptWidget;
    private readonly PendingMessagesWidget $pendingWidget;
    private readonly LoaderWidget $workingWidget;
    private readonly StatusPanelWidget $statusPanelWidget;
    private readonly CompactHeaderWidget $compactHeaderWidget;
    private readonly LiveTextWidget $editorSepWidget;
    private readonly FooterBarWidget $footerWidget;
    private readonly LiveTextWidget $footerSepWidget;
    private readonly FooterDataProvider $footerDataProvider;

    /* ── Status/working state (sole owner/writer; synced to native widgets) ── */
    /** @var array<string, string> */
    private array $statusEntries = [];
    private string $workingMessage = '';
    private bool $workingVisible = true;

    /* ── Overlay management ── */
    private ?Tui $tui = null;

    /* ── Mount flag ── */
    private bool $mounted = false;

    public function __construct(
        private readonly TuiTheme $theme,
        private string $sessionId,
        private readonly PromptEditor $promptEditor,
        TranscriptDisplayConfig $displayConfig = new TranscriptDisplayConfig(),
        TranscriptDisplayState $displayState = new TranscriptDisplayState(),
    ) {
        // ── Instantiate directly mounted native chrome widgets ──
        $this->headerWidget = new HeaderWidget($theme);
        $this->transcriptWidget = new TranscriptMountedWidget(
            theme: $theme,
            displayConfig: $displayConfig,
            displayState: $displayState,
        );
        $this->pendingWidget = new PendingMessagesWidget($theme);
        $this->statusPanelWidget = new StatusPanelWidget($theme);
        $this->footerDataProvider = new FooterDataProvider();
        $this->footerDataProvider->setProvider('_default', $this->createDefaultFooterProvider());
        $this->footerWidget = new FooterBarWidget($theme, $this->footerDataProvider);
        $this->loadedResourcesWidget = new LoadedResourcesWidget($theme);
        $this->compactHeaderWidget = new CompactHeaderWidget($theme);

        // ── Top margin ──
        // Produces TOP_MARGIN_LINES blank lines.  Unlike TextWidget,
        // LiveTextWidget preserves empty lines so the margin renders.
        $this->topMarginWidget = new LiveTextWidget(
            static fn (RenderContext $ctx) => str_repeat("\n", self::TOP_MARGIN_LINES - 1),
        );

        // ═══════════════════════════════════════════════════
        //  Responsive separators (bounded LiveTextWidget KEEP)
        //
        //  Only the blank top margin and the three separator rows still use
        //  LiveTextWidget: Symfony TextWidget drops whitespace-only text and
        //  there is no native scrollback/separator primitive that is exact.
        // ═══════════════════════════════════════════════════

        // ── Separator (used for all separator rows) ──
        $this->headerSepWidget = new LiveTextWidget(
            function (RenderContext $symfonyCtx): string {
                return $this->theme->color(
                    ThemeColorEnum::Separator,
                    str_repeat('─', $symfonyCtx->getColumns()),
                );
            },
        );

        // Transcript is mounted as a first-class Symfony widget subtree
        // (constructed above). Markdown children receive live WidgetContext.

        // ── Working status ──
        // One native LoaderWidget for active/idle/hidden: start() while working,
        // stop()+finished indicator for idle/hidden so the two-line footprint stays stable.
        $this->workingWidget = (new LoaderWidget(''))->setSpinner('circle');
        $this->syncWorkingSlot();

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

        // ── Footer separator ──
        $this->footerSepWidget = new LiveTextWidget(
            function (RenderContext $symfonyCtx): string {
                return $this->theme->color(
                    ThemeColorEnum::Separator,
                    str_repeat('─', $symfonyCtx->getColumns()),
                );
            },
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

        // Install themed widget stylesheets before mounting transcript children so
        // attached widgets resolve Hatfield theme tokens.
        $tui->addStyleSheet((new ThemeStyleSheetFactory())->createMarkdown($this->theme->getPalette()));
        $tui->addStyleSheet((new ThemeStyleSheetFactory())->createHotkeyTable($this->theme->getPalette()));
        $tui->addStyleSheet((new ThemeStyleSheetFactory())->createSubagentProgressCard($this->theme->getPalette()));
        $tui->addStyleSheet((new ThemeStyleSheetFactory())->createQuestionChoiceList($this->theme->getPalette()));

        // Theme the native working loader with the existing Working palette token.
        $workingColor = $this->theme->getPalette()->get(ThemeColorEnum::Working);
        if ('' !== $workingColor) {
            $workingStyle = new Style(color: $workingColor);
            $tui->addStyleSheet(new StyleSheet([
                LoaderWidget::class.'::spinner' => $workingStyle,
                LoaderWidget::class.'::message' => $workingStyle,
            ]));
        }
        // Indent spinner+message two columns to match the historical "  ◐ msg" layout.
        $this->workingWidget->setStyle(new Style(padding: Padding::from([0, 0, 0, 2])));

        // Add widgets in display order (top → bottom).
        $tui->add($this->topMarginWidget);
        $tui->add($this->headerWidget);
        $tui->add($this->headerSepWidget);
        $tui->add($this->loadedResourcesWidget);
        $tui->add($this->transcriptWidget);
        $tui->add($this->pendingWidget);
        $tui->add($this->workingWidget);
        $tui->add($this->statusPanelWidget);
        $tui->add($this->compactHeaderWidget);
        $tui->add($this->editorSepWidget);
        $tui->add($this->promptEditor->getWidget());

        // App shortcuts live on the mounted editor so InputEvent listeners
        // match through the context-shared KeyParser (legacy + Kitty).
        $this->promptEditor->setKeybindings(AppShortcutKeybindings::create());

        // The PromptEditor wraps an EditorWidget that is a DI singleton
        // reused across TUI iterations during session switches.  After
        // the old TUI stopped, the widget's render cache still holds
        // lines computed for the old TUI's dimensions and stylesheet.
        // Invalidate it here so the first render in the new TUI
        // re-computes the frame/border and editor content correctly.
        $this->promptEditor->getWidget()->invalidate();

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
        $this->loadedResourcesWidget->setSummary($summary);
    }

    public function hasLoadedResourcesBlock(): bool
    {
        return $this->loadedResourcesWidget->hasContent();
    }

    public function toggleLoadedResourcesExpanded(): void
    {
        $this->loadedResourcesWidget->toggleExpanded();
    }

    /**
     * Full transcript replacement (bootstrap, resume, history position, preview invalidation).
     *
     * @param list<TranscriptBlock> $blocks
     */
    public function setTranscriptBlocks(array $blocks): void
    {
        $this->transcriptWidget->setBlocks($blocks);
    }

    /**
     * Ordinary live projector delta: tail append/update/remove.
     * Presentation model applies a dependency-bounded visual patch (no full-history
     * walk on pure tail stream); explicit full reproject remains for structural cases.
     */
    public function applyTranscriptChangeSet(TranscriptChangeSet $changes): void
    {
        $this->transcriptWidget->applyChangeSet($changes);
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
        $next = array_values($queuedMessages);
        if ($next === $this->pendingWidget->messages()) {
            return;
        }

        $this->pendingWidget->setMessages($next);
    }

    public function setWorkingMessage(?string $message): void
    {
        $normalized = $message ?? '';
        if ($normalized === $this->workingMessage) {
            return;
        }

        $this->workingMessage = $normalized;
        $this->syncWorkingSlot();
    }

    public function setWorkingVisible(bool $visible): void
    {
        if ($visible === $this->workingVisible) {
            return;
        }

        $this->workingVisible = $visible;
        $this->syncWorkingSlot();
    }

    public function setStatus(string $key, ?string $text): void
    {
        $current = $this->statusEntries[$key] ?? null;
        if ($text === $current) {
            return;
        }

        // Panel-only: keyed statuses never fan out into the footer bar.
        // Footer content comes from explicit FooterSegmentProvider APIs.
        // Push the full entry map so the native StatusPanelWidget repaints.
        if (null === $text) {
            unset($this->statusEntries[$key]);
        } else {
            $this->statusEntries[$key] = $text;
        }
        $this->statusPanelWidget->setEntries($this->statusEntries);
    }

    /**
     * Remove the transient Shift+Tab reasoning level line from the status panel.
     *
     * Does not change {@see TuiSessionState::footerReasoning}, editor border
     * colour, or footer diamond/model styling — only the panel-only notice.
     */
    public function clearTransientReasoningNotice(): void
    {
        $this->setStatus('reasoning', null);
    }

    /**
     * Apply the editor border colour matching the current reasoning level.
     *
     * Maps reasoning levels (off, minimal, low, medium, high, xhigh, max) to the
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
     * Invalidate only the footer widget (elapsed time and footer segments).
     */
    public function refreshFooter(): void
    {
        $this->footerWidget->invalidate();
    }

    /**
     * Invalidate all mutable widgets so they re-render on the next tick.
     *
     * Status entries, pending messages, and snapshots change via ChatScreen
     * mutators and already invalidate their targeted widgets. This method
     * is a safety net for external state changes.
     */
    public function refresh(): void
    {
        // Transcript is a mounted Symfony subtree; children invalidate themselves.
        $this->pendingWidget->invalidate();
        $this->workingWidget->invalidate();
        $this->compactHeaderWidget->invalidate();
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
     *   … → status → compactHeader → overlay → editorSep → editor →
     *   footerSep → footer
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
        $this->tui->remove($this->promptEditor->getWidget());
        $this->tui->remove($this->editorSepWidget);

        // Add the overlay (appended after the compact header).
        $this->tui->add($widget);

        // Restore editor area widgets in original mount order.
        $this->tui->add($this->editorSepWidget);
        $this->tui->add($this->promptEditor->getWidget());
        $this->tui->add($this->footerSepWidget);
        $this->tui->add($this->footerWidget);
    }

    /**
     * Insert an interactive overlay widget below the editor.
     *
     * Removes the footer, adds the overlay, then
     * restores everything in original order so the overlay renders
     * directly below the editor:
     *
     *   … → editor → overlay → footerSep → footer
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

        // Add the overlay (appended after the editor).
        $this->tui->add($widget);

        // Restore footer widgets in original mount order.
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

    /**
     * Current working message ('' when idle).
     */
    public function workingMessage(): string
    {
        return $this->workingMessage;
    }

    /**
     * The screen-owned compact-header widget (above the editor).
     *
     * Narrow internal getter for {@see CompactHeaderRegistrar} so the
     * registrar drives the directly mounted widget instead of a registry
     * slot. ChatScreen already exposes other widget owners/theme.
     */
    public function compactHeaderWidget(): CompactHeaderWidget
    {
        return $this->compactHeaderWidget;
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

    /**
     * Current session id shown in the footer (empty for draft sessions).
     */
    public function sessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * Drive the single working-slot LoaderWidget from screen state.
     *
     * Visible + non-empty message → circle spinner + message (started).
     * Visible + empty message → finished indicator "●" + "idle".
     * Hidden → finished indicator space + empty message (native two-line reserve).
     *
     * stop() marks finished even when already stopped, so idle/hidden always go
     * through the same finished-state path.
     */
    private function syncWorkingSlot(): void
    {
        $visible = $this->workingVisible;
        $message = $this->workingMessage;

        if (!$visible) {
            $this->workingWidget->setFinishedIndicator(' ');
            $this->workingWidget->setMessage('');
            $this->workingWidget->stop();

            return;
        }

        if ('' === $message) {
            $this->workingWidget->setFinishedIndicator('●');
            $this->workingWidget->setMessage('idle');
            $this->workingWidget->stop();

            return;
        }

        $this->workingWidget->setMessage($message);
        if (!$this->workingWidget->isRunning()) {
            $this->workingWidget->start();
        }
    }

    /* ────────── Helpers ────────── */

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
