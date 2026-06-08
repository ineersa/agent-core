<?php

declare(strict_types=1);

namespace Ineersa\Tui\Picker;

use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\ModelSelectionService;
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
 * Interactive favorites picker accessed via /model fav.
 *
 * Shows all available models with * markers for favorites.
 * Space toggles favorite on the selected row.
 * Enter closes the picker (no model change).
 * Escape cancels without changes.
 */
final class FavoritePickerController
{
    private ?PickerOverlay $overlay = null;

    private ?Tui $tui = null;
    private ?ChatScreen $screen = null;
    private ?TuiSessionState $state = null;

    public function __construct(
        private readonly ModelSelectionService $modelService,
        private readonly LoggerInterface $logger,
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
        $headerText = $screen->theme()->muted(
            'Favorite models — arrows move, Space toggles favorite, Enter saves, Esc cancels',
        );
        $header = new TextWidget(
            text: $headerText,
            truncate: true,
        );

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

        $listWidget = new SelectListWidget(
            items: $items,
            maxVisible: 10,
            keybindings: $kb,
        );

        // ── Space toggles favorite ──
        $modelService = $this->modelService;
        $logger = $this->logger;

        $listWidget->onInput(static function (string $data) use (
            $modelService, $listWidget, $screen, $logger, $state,
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
            } catch (\RuntimeException $e) {
                $logger->warning('Failed to toggle favorite from favorites picker', [
                    'exception' => $e,
                    'model' => $ref->toString(),
                ]);

                $screen->setStatus('error', 'Error: '.$e->getMessage());
                $screen->refresh();

                return true;
            }

            // Rebuild items with updated favorite markers
            $newItems = FavoritePickerController::buildFavoritesItems($modelService, $screen->theme(), $state);
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
        $controller = $this;

        $listWidget->onSelect(static function (SelectEvent $event) use ($controller): void {
            $controller->closePicker();
        });

        // ── Escape → close ──
        $listWidget->onCancel(static function (CancelEvent $event) use ($controller): void {
            $controller->closePicker();
        });

        // ── Mount via PickerOverlay ──
        $this->overlay = new PickerOverlay();
        $this->overlay->mount($tui, $screen, $listWidget, $header);
    }

    public function isOpen(): bool
    {
        return $this->overlay?->isOpen() ?? false;
    }

    /**
     * Close the picker overlay (no-op if already closed).
     */
    public function closePicker(): void
    {
        $this->overlay?->close();
        $this->overlay = null;
    }

    /**
     * Build items: all models, favorites marked with *, current marked with ❯.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function buildFavoritesItems(ModelSelectionService $modelService, TuiTheme $theme, ?TuiSessionState $state = null): array
    {
        $all = $modelService->getAvailableModels();
        $favorites = $modelService->getFavoriteModels();
        $favSet = array_flip($favorites);
        $currentModel = null !== $state ? $modelService->getCurrentModel($state->sessionId) : null;
        $currentStr = null !== $currentModel ? $currentModel->toString() : null;

        $items = [];
        foreach ($all as $ref) {
            $refStr = $ref->toString();
            $isFav = isset($favSet[$refStr]);
            $isCurrent = $refStr === $currentStr;

            // Current model marker (accent) — matches the model picker pattern.
            $pointer = $isCurrent
                ? $theme->color(ThemeColorEnum::Accent, '❯')
                : ' ';

            // Favourite marker coloured with Warning token so
            // favourited rows stand out from plain ones.
            $marker = $isFav
                ? $theme->color(ThemeColorEnum::Warning, '*')
                : ' ';

            $label = \sprintf(
                '%s %s  %s',
                $pointer,
                $marker,
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
     * Build items: all models, favorites marked with *, current with ❯.
     *
     * @return list<array{value: string, label: string}>
     */
    private function buildItems(): array
    {
        return self::buildFavoritesItems($this->modelService, $this->screen->theme(), $this->state);
    }
}
