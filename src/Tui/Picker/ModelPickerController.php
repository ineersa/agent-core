<?php

declare(strict_types=1);

namespace Ineersa\Tui\Picker;

use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\Tui\Listener\FooterStateInitializer;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Ineersa\Tui\Widget\SelectListKeybindings;
use Psr\Log\LoggerInterface;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

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
    private ?PickerOverlay $overlay = null;

    public function __construct(
        private readonly Tui $tui,
        private readonly ChatScreen $screen,
        private readonly TuiSessionState $state,
        private readonly ModelSelectionService $modelService,
        private readonly AppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Open the interactive model picker on the TUI.
     *
     * Builds a SelectListWidget, mounts via PickerOverlay, sets focus, and
     * wires selection/cancellation/favorite-toggle callbacks.
     */
    public function open(): void
    {
        if ($this->overlay?->isOpen() ?? false) {
            return;
        }

        $tui = $this->tui;
        $screen = $this->screen;
        $state = $this->state;

        // ── Header — instructional line above the list (muted theme colour) ──
        $headerText = '' !== $state->footerModel
            ? $screen->theme()->muted('Select a model — arrows move, Enter selects, Esc cancels')
            : 'Select a model — arrows move, Enter selects, Esc cancels';
        $header = new TextWidget(
            text: $headerText,
            truncate: true,
        );

        // Standard select actions plus toggle_favorite (ctrl+f).
        // cursor_right is unbound so Ctrl+F is not stolen by page-right.
        $kb = SelectListKeybindings::withFavoriteToggle();

        // ── Build items: favorites-first with markers ──
        $items = $this->buildItems();

        $listWidget = new SelectListWidget(
            items: $items,
            maxVisible: SelectListKeybindings::MAX_VISIBLE,
            keybindings: $kb,
        );

        // ── Ctrl+F favorite toggle ──
        $modelService = $this->modelService;
        $logger = $this->logger;

        $listWidget->onInput(static function (string $data) use (
            $screen, $state, $modelService, $listWidget, $logger,
        ): bool {
            if (!$listWidget->getKeybindings()->matches($data, 'toggle_favorite')) {
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
            } catch (\RuntimeException $e) {
                $logger->warning('Failed to toggle favorite from picker', [
                    'exception' => $e,
                    'model' => $ref->toString(),
                ]);

                $screen->setStatus('error', 'Error: '.$e->getMessage());
                $screen->refresh();

                return true;
            }

            // Rebuild items with updated favorite markers
            $newItems = ModelPickerController::buildItemsStatic($modelService, $state, $screen->theme());
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

        // ── Enter → select model, persist, close ──
        $controller = $this;

        $listWidget->onSelect(static function (SelectEvent $event) use ($controller): void {
            $item = $event->getItem();
            $ref = AiModelReference::tryParse($item['value']);
            if (null === $ref) {
                return;
            }

            $controller->applySelectEffect($ref);
            $controller->closePicker();
        });

        // ── Escape / Ctrl+C → close without change ──
        $listWidget->onCancel(static function (CancelEvent $event) use ($controller): void {
            $controller->closePicker();
        });

        // ── Mount via PickerOverlay ──
        $this->overlay = new PickerOverlay();
        $this->overlay->mount($tui, $screen, $listWidget, $header);
    }

    /**
     * Whether the picker is currently visible.
     */
    public function isOpen(): bool
    {
        return $this->overlay?->isOpen() ?? false;
    }

    /**
     * Static variant so the onInput closure can rebuild without $this capture.
     *
     * The current model item is distinguished by a coloured ❯ marker;
     * favourite items by a coloured ★ marker.  No description field is
     * set — visual distinction relies on theme-coloured markers layered
     * on top of SelectListWidget's selected-row bold styling.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function buildItemsStatic(ModelSelectionService $modelService, TuiSessionState $state, TuiTheme $theme): array
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

            // Colour markers with semantic theme tokens so rows are
            // visibly styled rather than all-white.  Selected-row bold
            // from SelectListWidget\'s stylesheet layers on top.
            $pointer = $isCurrent
                ? $theme->color(ThemeColorEnum::Accent, '❯')
                : ' ';
            $star = $isFav
                ? $theme->color(ThemeColorEnum::Warning, '★')
                : ' ';

            $label = \sprintf(
                '%s %s  %s',
                $pointer,
                $star,
                $isCurrent
                    ? $theme->color(ThemeColorEnum::Accent, $refStr)
                    : $refStr,
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
     * Uses controller-owned dependencies bound via the constructor.
     *
     * @internal called from static closures within {@see open()}
     */
    public function applySelectEffect(AiModelReference $ref): void
    {
        try {
            $this->modelService->changeModel($ref, $this->state->sessionId);
        } catch (\RuntimeException $e) {
            $this->logger->warning('Failed to change model from picker', [
                'exception' => $e,
                'model' => $ref->toString(),
            ]);

            // Make the error visible in the TUI status bar.
            $this->screen->setStatus('error', 'Error: '.$e->getMessage());

            return;
        }

        // Update footer state — reset reasoning to off when model doesn't support thinking
        FooterStateInitializer::applyModelSelection($this->state, $ref, $this->modelService, $this->appConfig);

        // Apply editor border colour matching the new reasoning level.
        $this->screen->applyEditorBorderColor($this->state->footerReasoning);

        $this->screen->refresh();
    }

    /**
     * Close the picker overlay.
     *
     * Delegates to PickerOverlay::close() which removes the container
     * from the TUI and resets internal state.
     */
    public function closePicker(): void
    {
        $this->overlay?->close();
        $this->overlay = null;
    }

    // ── Internal helpers ──

    /**
     * Build item list: favorites first with ★, current with ❯.
     *
     * @return list<array{value: string, label: string}>
     */
    private function buildItems(): array
    {
        return self::buildItemsStatic($this->modelService, $this->state, $this->screen->theme());
    }
}
