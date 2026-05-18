<?php

declare(strict_types=1);

namespace Ineersa\Tui\Picker;

use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\SelectListWidget;

/**
 * Interactive favorites picker accessed via /model fav.
 *
 * Shows all available models with * markers for favorites.
 * Space toggles favorite on the selected row.
 * Enter closes the picker (no model change).
 * Escape cancels without changes.
 */
final class FavoritePickerController
{
    private ?SelectListWidget $listWidget = null;
    private bool $isOpen = false;

    private ?Tui $tui = null;
    private ?ChatScreen $screen = null;
    private ?TuiSessionState $state = null;

    public function __construct(
        private readonly ModelSelectionService $modelService,
    ) {
    }

    public function setRuntimeRefs(Tui $tui, ChatScreen $screen, TuiSessionState $state): void
    {
        $this->tui = $tui;
        $this->screen = $screen;
        $this->state = $state;
    }

    public function open(): void
    {
        if ($this->isOpen || null === $this->tui || null === $this->screen || null === $this->state) {
            return;
        }

        $tui = $this->tui;
        $screen = $this->screen;
        $state = $this->state;

        // Keybindings: arrows, space, enter, escape — no ctrl+f
        $kb = new Keybindings([
            'select_up' => [Key::UP],
            'select_down' => [Key::DOWN],
            'select_page_up' => [Key::PAGE_UP],
            'select_page_down' => [Key::PAGE_DOWN],
            'select_confirm' => [Key::ENTER],
            'select_cancel' => [Key::ESCAPE, Key::ctrl('c')],
        ]);

        $items = $this->buildItems();

        $this->listWidget = new SelectListWidget(
            items: $items,
            maxVisible: 10,
            keybindings: $kb,
        );

        // ── Space toggles favorite ──
        $modelService = $this->modelService;
        $listWidget = $this->listWidget;

        $this->listWidget->onInput(static function (string $data) use (
            $modelService, $listWidget, $screen,
        ): bool {
            // We handle space via select_toggle_fav in keybindings,
            // but SelectListWidget routes confirm to select_confirm.
            // We need to intercept AFTER keybindings match or use
            // onInput before keybindings. The KeybindingsTrait
            // checks onInput BEFORE matching, so we can match
            // the raw space character here.
            if (' ' !== $data) {
                return false;
            }

            $selected = $listWidget->getSelectedItem();
            if (null === $selected) {
                return true;
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
            $newItems = FavoritePickerController::buildFavoritesItems($modelService);
            $listWidget->setItems($newItems);

            // Restore selection
            $newSelIdx = ModelPickerController::findItemIndex($newItems, $ref->toString());
            if (null !== $newSelIdx) {
                $listWidget->setSelectedIndex($newSelIdx);
            }

            $screen->refresh();

            return true; // consumed
        });

        // ── Enter → close ──
        $onSelectTui = $tui;
        $onSelectList = $this->listWidget;
        $onSelectController = $this;

        $this->listWidget->onSelect(static function (SelectEvent $event) use (
            $onSelectTui, $onSelectList, $onSelectController,
        ): void {
            $onSelectController->applyCloseEffect($onSelectTui, $onSelectList);
        });

        // ── Escape → close ──
        $onCancelTui = $tui;
        $onCancelList = $this->listWidget;
        $onCancelController = $this;

        $this->listWidget->onCancel(static function (CancelEvent $event) use (
            $onCancelTui, $onCancelList, $onCancelController,
        ): void {
            $onCancelController->applyCloseEffect($onCancelTui, $onCancelList);
        });

        // Mount and focus
        $tui->add($this->listWidget);
        $tui->setFocus($this->listWidget);
        $tui->requestRender(true);
        $this->isOpen = true;
    }

    public function isOpen(): bool
    {
        return $this->isOpen;
    }

    public function applyCloseEffect(Tui $tui, SelectListWidget $listWidget): void
    {
        $tui->remove($listWidget);
        if ($listWidget === $this->listWidget) {
            $this->listWidget = null;
        }
        $this->isOpen = false;
    }

    /**
     * Build items: all models, favorites marked with *.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function buildFavoritesItems(ModelSelectionService $modelService): array
    {
        $all = $modelService->getAvailableModels();
        $favorites = $modelService->getFavoriteModels();
        $favSet = array_flip($favorites);

        $items = [];
        foreach ($all as $ref) {
            $refStr = $ref->toString();
            $isFav = isset($favSet[$refStr]);

            $label = \sprintf(
                '%s %s',
                $isFav ? '*' : ' ',
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
     * Build items: all models, favorites marked with *.
     *
     * @return list<array{value: string, label: string}>
     */
    private function buildItems(): array
    {
        return self::buildFavoritesItems($this->modelService);
    }
}
