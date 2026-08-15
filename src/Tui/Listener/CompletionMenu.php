<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Completion\CompletionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Event\SelectionChangeEvent;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Transient completion menu overlay rendered below the editor.
 *
 * Owns the {@see SelectListWidget} and {@see ContainerWidget} lifecycle:
 * open (build + mount), update (sync items), close (remove).
 *
 * The SelectListWidget is intentionally NOT focused; the editor keeps
 * focus so printable typing flows into the editor while the completion
 * menu stays visible. Navigation is driven by {@see CompletionListener}
 * forwarding raw Up/Down into {@see SelectListWidget::handleInput()} so
 * the native widget owns wrapping, visible-window scrolling, and
 * {@see SelectionChangeEvent} dispatch. Accept / cancel remain listener-owned.
 *
 * Selected-row highlighting uses the app theme accent colour. On selection
 * change the labels are rebuilt so the accent follows the native selection.
 *
 * Uses {@see ChatScreen::insertOverlayAfterEditor()} so the menu renders
 * below the prompt instead of overlaying it.
 */
final class CompletionMenu
{
    private ?ContainerWidget $container = null;
    private ?SelectListWidget $listWidget = null;

    /** Guards setItems()/setSelectedIndex() inside selection-change restyle. */
    private bool $restylingSelection = false;

    public function __construct(
        private readonly TuiTheme $theme,
    ) {
    }

    /**
     * Build and mount the completion menu overlay.
     *
     * Safe to call when already open — the previous overlay is torn down
     * first so callers don't need to track close-before-open manually.
     */
    public function open(ChatScreen $screen, CompletionState $state): void
    {
        if (null !== $this->container) {
            $this->close($screen);
        }

        $this->container = new ContainerWidget();

        $header = new TextWidget(
            text: $this->theme->muted(
                'Completions — arrows move, Tab inserts, Enter accepts+submits, Esc closes',
            ),
            truncate: true,
        );
        $this->container->add($header);

        $this->listWidget = new SelectListWidget(
            items: self::buildItems($state->getSuggestions(), $this->theme, 0),
            maxVisible: 10,
        );
        $this->listWidget->setSelectedIndex(0);
        $this->listWidget->onSelectionChange(
            function (SelectionChangeEvent $event) use ($state): void {
                if (null === $this->listWidget || $this->restylingSelection) {
                    return;
                }
                $selectedIndex = (int) $event->getValue();
                $items = self::buildItems($state->getSuggestions(), $this->theme, $selectedIndex);
                // setItems() resets selection to 0; restore without re-entering this callback.
                $this->restylingSelection = true;
                try {
                    $this->listWidget->setItems($items);
                    $this->listWidget->setSelectedIndex($selectedIndex);
                } finally {
                    $this->restylingSelection = false;
                }
            },
        );
        $this->container->add($this->listWidget);

        $screen->insertOverlayAfterEditor($this->container);
    }

    /**
     * Sync the SelectListWidget with a new suggestion set (live typing).
     *
     * Resets selection to the first item. Does not destroy the overlay.
     */
    public function update(ChatScreen $screen, CompletionState $state): void
    {
        if (null === $this->listWidget) {
            return;
        }

        $items = self::buildItems($state->getSuggestions(), $this->theme, 0);
        $this->listWidget->setItems($items);
        $this->listWidget->setSelectedIndex(0);
    }

    /**
     * Forward raw input to the unfocused SelectListWidget (Up/Down navigation).
     */
    public function handleNavigationInput(string $data): void
    {
        $this->listWidget?->handleInput($data);
    }

    /**
     * Selected SelectListWidget item value (suggestion index as string), or null.
     */
    public function selectedValue(): ?string
    {
        $item = $this->listWidget?->getSelectedItem();

        return \is_array($item) && isset($item['value']) && \is_string($item['value'])
            ? $item['value']
            : null;
    }

    /**
     * Remove the overlay from the screen and reset internal state.
     *
     * Idempotent — safe to call any number of times.
     */
    public function close(ChatScreen $screen): void
    {
        if (null !== $this->container) {
            $screen->removeOverlay($this->container);
        }
        $this->container = null;
        $this->listWidget = null;
        $this->restylingSelection = false;
    }

    public function isOpen(): bool
    {
        return null !== $this->container;
    }

    /**
     * @param list<\Ineersa\Tui\Completion\CompletionSuggestion> $suggestions
     *
     * @return list<array{value: string, label: string, description?: string}>
     */
    private static function buildItems(
        array $suggestions,
        TuiTheme $theme,
        int $selectedIndex,
    ): array {
        $items = [];
        foreach ($suggestions as $i => $s) {
            $label = $i === $selectedIndex
                ? $theme->color(ThemeColorEnum::Accent, $s->display)
                : $s->display;

            $description = '' !== $s->description
                ? $theme->muted($s->description)
                : '';

            $item = [
                'value' => (string) $i,
                'label' => $label,
            ];

            if ('' !== $description) {
                $item['description'] = $description;
            }

            $items[] = $item;
        }

        return $items;
    }
}
