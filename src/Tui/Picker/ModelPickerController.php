<?php

declare(strict_types=1);

namespace Ineersa\Tui\Picker;

use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\Tui\Listener\FooterStateInitializer;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SelectionChangeEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\SelectListWidget;

/**
 * Manages the interactive model picker overlay lifecycle.
 *
 * Opens an interactive SelectListWidget when /model is invoked.
 * Arrow keys navigate; Enter selects; Ctrl+F toggles favorite;
 * Escape cancels.  Entries are sorted favorites-first with
 * ★ (favorite) and ❯ (current) markers.
 *
 * The controller is stateless between picker sessions — it creates
 * and destroys the SelectListWidget per invocation.
 */
final class ModelPickerController
{
    private ?SelectListWidget $listWidget = null;
    private bool $isOpen = false;

    private ?Tui $tui = null;
    private ?ChatScreen $screen = null;
    private ?TuiSessionState $state = null;

    public function __construct(
        private readonly ModelSelectionService $modelService,
        private readonly AppConfig $appConfig,
    ) {
    }

    /**
     * Set the per-run TUI references that are only available at
     * listener registration time (called by ModelControlListener).
     */
    public function setRuntimeRefs(Tui $tui, ChatScreen $screen, TuiSessionState $state): void
    {
        $this->tui = $tui;
        $this->screen = $screen;
        $this->state = $state;
    }

    /**
     * Open the interactive model picker on the TUI (no-arg, uses references
     * previously set via {@see setRuntimeRefs()}).
     *
     * Builds a SelectListWidget, adds it to the root container, sets
     * focus, and wires selection/cancellation/favorite-toggle callbacks.
     */
    public function open(): void
    {
        if ($this->isOpen || null === $this->tui || null === $this->screen || null === $this->state) {
            return;
        }

        $tui = $this->tui;
        $screen = $this->screen;
        $state = $this->state;

        // ── Keybindings: remove ctrl+f from cursor_right so we can intercept it ──
        $kb = new Keybindings([
            'select_up' => [Key::UP],
            'select_down' => [Key::DOWN],
            'select_page_up' => [Key::PAGE_UP],
            'select_page_down' => [Key::PAGE_DOWN],
            'select_confirm' => [Key::ENTER],
            'select_cancel' => [Key::ESCAPE, Key::ctrl('c')],
            // cursor_left / cursor_right omitted (no paging via left/right)
        ]);

        // ── Build items: favorites-first with markers ──
        $items = $this->buildItems();

        $this->listWidget = new SelectListWidget(
            items: $items,
            maxVisible: 10,
            keybindings: $kb,
        );

        // ── Ctrl+F favorite toggle ──
        $modelService = $this->modelService;
        $listWidget = $this->listWidget;

        $this->listWidget->onInput(static function (string $data) use (
            $screen, $state, $modelService, $listWidget,
        ): bool {
            // Ctrl+F sends \x06 in most terminals
            if ("\x06" !== $data) {
                return false;
            }

            $selected = $listWidget->getSelectedItem();
            if (null === $selected) {
                return true; // consume but nothing to do
            }

            $ref = AiModelReference::tryParse($selected['value']);
            if (null === $ref) {
                return true;
            }

            try {
                $modelService->toggleFavorite($ref);
            } catch (\RuntimeException) {
                return true;
            }

            // Rebuild items with updated favorite markers
            $newItems = ModelPickerController::buildItemsStatic($modelService, $state);
            $listWidget->setItems($newItems);

            // Restore selection to the same model if it's still visible
            // (toggle doesn't remove items, just changes marker)
            $newSelIdx = ModelPickerController::findItemIndex($newItems, $ref->toString());
            if (null !== $newSelIdx) {
                $listWidget->setSelectedIndex($newSelIdx);
            }

            $screen->refresh();

            return true; // consumed
        });

        // ── Selection change → show model name in status ──
        $this->listWidget->onSelectionChange(static function (SelectionChangeEvent $event) use ($screen): void {
            $item = $event->getItem();
            $screen->setStatus('model', 'Model: '.$item['value']);
            $screen->refresh();
        });

        // ── Enter → select model, persist, close ──
        $onSelectService = $this->modelService;
        $onSelectAppConfig = $this->appConfig;
        $onSelectState = $this->state;
        $onSelectScreen = $screen;
        $onSelectTui = $tui;
        $onSelectList = $this->listWidget;
        $onSelectController = $this;

        $this->listWidget->onSelect(static function (SelectEvent $event) use (
            $onSelectService, $onSelectAppConfig, $onSelectState,
            $onSelectScreen, $onSelectTui, $onSelectList, $onSelectController,
        ): void {
            $item = $event->getItem();
            $ref = AiModelReference::tryParse($item['value']);
            if (null === $ref) {
                return;
            }

            $onSelectController->applySelectEffect(
                $ref, $onSelectService, $onSelectAppConfig,
                $onSelectState, $onSelectScreen,
            );
            $onSelectController->applyCloseEffect($onSelectTui, $onSelectList);
        });

        // ── Escape / Ctrl+C → close without change ──
        $onCancelTui = $tui;
        $onCancelList = $this->listWidget;
        $onCancelController = $this;

        $this->listWidget->onCancel(static function (CancelEvent $event) use (
            $onCancelTui, $onCancelList, $onCancelController,
        ): void {
            $onCancelController->applyCloseEffect($onCancelTui, $onCancelList);
        });

        // ── Mount and focus ──
        $tui->add($this->listWidget);
        $tui->setFocus($this->listWidget);
        $tui->requestRender(true);
        $this->isOpen = true;
    }

    /**
     * Whether the picker is currently visible.
     */
    public function isOpen(): bool
    {
        return $this->isOpen;
    }

    /**
     * Static variant so the onInput closure can rebuild without $this capture.
     *
     * @return list<array{value: string, label: string}>
     */
    /**
     * @return list<array{value: string, label: string}>
     */
    public static function buildItemsStatic(ModelSelectionService $modelService, TuiSessionState $state): array
    {
        $ordered = $modelService->getOrderedModels();
        $favorites = $modelService->getFavoriteModels();
        $favSet = array_flip($favorites);
        $currentModel = $modelService->getCurrentModel($state->sessionId);
        $currentStr = null !== $currentModel ? $currentModel->toString() : null;

        $items = [];
        foreach ($ordered as $ref) {
            $refStr = $ref->toString();
            $isFav = isset($favSet[$refStr]);
            $isCurrent = $refStr === $currentStr;

            $label = \sprintf(
                '%s %s  %s',
                $isCurrent ? '❯' : ' ',
                $isFav ? '★' : ' ',
                $refStr,
            );

            $items[] = [
                'value' => $refStr,
                'label' => $label,
            ];
        }

        return $items;
    }

    /**
     * Find the index of a value in the items array.
     *
     * @param list<array{value: string, label?: string}> $items
     */
    public static function findItemIndex(array $items, string $value): ?int
    {
        foreach ($items as $i => $item) {
            if ($item['value'] === $value) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Execute model selection, persist, and update footer state.
     *
     * Called from within a static closure on SelectEvent — all
     * dependencies are passed explicitly.
     */
    public function applySelectEffect(
        AiModelReference $ref,
        ModelSelectionService $modelService,
        AppConfig $appConfig,
        TuiSessionState $state,
        ChatScreen $screen,
    ): void {
        try {
            $modelService->changeModel($ref, $state->sessionId);
        } catch (\RuntimeException) {
            $screen->setStatus('model', 'Failed to select model: '.$ref->toString());
            $screen->refresh();

            return;
        }

        // Update footer state
        $state->footerModel = FooterStateInitializer::shortModelName(
            $ref->providerId.'/'.$ref->modelName,
        );
        $state->footerReasoning = $modelService->getCurrentReasoning($state->sessionId);
        $state->contextWindow = self::lookupContextWindow($appConfig, $ref);

        $screen->setStatus('model', 'Selected: '.$ref->toString());
        $screen->refresh();
    }

    /**
     * Remove the picker widget and mark the controller as closed.
     *
     * Called from within a static closure on SelectEvent/CancelEvent —
     * all dependencies are passed explicitly.
     */
    public function applyCloseEffect(Tui $tui, SelectListWidget $listWidget): void
    {
        $tui->remove($listWidget);
        if ($listWidget === $this->listWidget) {
            $this->listWidget = null;
        }
        $this->isOpen = false;
    }

    // ── Internal helpers ──

    /**
     * Build item list: favorites first with ★, current with ❯.
     *
     * @return list<array{value: string, label: string}>
     */
    private function buildItems(): array
    {
        return self::buildItemsStatic($this->modelService, $this->state);
    }

    private static function lookupContextWindow(AppConfig $appConfig, AiModelReference $ref): int
    {
        $catalog = $appConfig->catalog;
        if (null === $catalog) {
            return 0;
        }

        $definition = $catalog->getModel($ref);

        return null !== $definition ? ($definition->contextWindow ?? 0) : 0;
    }
}
