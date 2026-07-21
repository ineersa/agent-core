<?php

declare(strict_types=1);

namespace Ineersa\Tui\Picker;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\Hatfield\ExtensionApi\Model\AiModelReference;
use Ineersa\Tui\Listener\FooterStateInitializer;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Psr\Log\LoggerInterface;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
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

    private ?Tui $tui = null;
    private ?ChatScreen $screen = null;
    private ?TuiSessionState $state = null;

    public function __construct(
        private readonly ModelSelectionService $modelService,
        private readonly AppConfig $appConfig,
        private readonly LoggerInterface $logger,
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
     * Builds a SelectListWidget, mounts via PickerOverlay, sets focus, and
     * wires selection/cancellation/favorite-toggle callbacks.
     */
    public function open(): void
    {
        if ($this->overlay?->isOpen() ?? false) {
            return;
        }

        if (null === $this->tui || null === $this->screen || null === $this->state) {
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

        $listWidget = new SelectListWidget(
            items: $items,
            maxVisible: 10,
            keybindings: $kb,
        );

        // ── Ctrl+F favorite toggle ──
        $modelService = $this->modelService;
        $logger = $this->logger;

        $listWidget->onInput(static function (string $data) use (
            $screen, $state, $modelService, $listWidget, $logger,
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
     * Uses controller-owned dependencies set via the constructor and
     * per-run refs set via {@see setRuntimeRefs()}.
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
        $this->state->footerModel = FooterStateInitializer::shortModelName(
            $ref->providerId.'/'.$ref->modelName,
        );
        $this->state->footerReasoning = $this->modelService->getDisplayReasoning($this->state->sessionId);
        $this->state->contextWindow = FooterStateInitializer::resolveContextWindowForRef($this->appConfig, $ref);

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
