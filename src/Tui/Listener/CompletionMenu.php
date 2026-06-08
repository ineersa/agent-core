<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Completion\CompletionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Transient completion menu overlay rendered below the editor.
 *
 * Owns the {@see SelectListWidget} and {@see ContainerWidget} lifecycle:
 * open (build + mount), update (sync items/selection), close (remove).
 *
 * The SelectListWidget is intentionally NOT focused; the editor keeps
 * focus so printable typing flows into the editor while the completion
 * menu stays visible.  Navigation / accept / cancel is driven by the
 * {@see CompletionListener} InputEvent handler, not by SelectListWidget's
 * built-in keybindings.
 *
 * Selected-row highlighting uses the app theme accent colour, matching the
 * visual pattern established by ModelPickerController and QuestionController
 * where labels are theme-coloured before being passed into SelectListWidget.
 * SelectListWidget's native selected style (bold) layers on top.
 *
 * Uses {@see ChatScreen::insertOverlayAfterEditor()} so the menu renders
 * below the prompt instead of overlaying it (unlike question/picker menus
 * which render above the editor and steal focus).
 */
final class CompletionMenu
{
    private ?ContainerWidget $container = null;
    private ?SelectListWidget $listWidget = null;

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
        // Tear down any previous overlay to avoid widget accumulation.
        if (null !== $this->container) {
            $this->close($screen);
        }

        $this->container = new ContainerWidget();

        $header = new TextWidget(
            text: $this->theme->muted(
                'Slash commands — arrows move, Tab inserts, Enter runs, Esc closes',
            ),
            truncate: true,
        );
        $this->container->add($header);

        $items = self::buildItems(
            $state->getSuggestions(),
            $this->theme,
            $state->getSelectedIndex(),
        );

        $this->listWidget = new SelectListWidget(
            items: $items,
            maxVisible: 10,
        );
        $this->listWidget->setSelectedIndex($state->getSelectedIndex());
        $this->container->add($this->listWidget);

        $screen->insertOverlayAfterEditor($this->container);
    }

    /**
     * Sync the SelectListWidget with the latest {@see CompletionState}.
     *
     * Updates items (for suggestion-set changes, e.g. live typing) and
     * selected index (for navigation).  Does NOT destroy/recreate the
     * overlay — the same SelectListWidget stays in the widget tree.
     */
    public function update(ChatScreen $screen, CompletionState $state): void
    {
        if (null === $this->listWidget) {
            return;
        }

        $items = self::buildItems(
            $state->getSuggestions(),
            $this->theme,
            $state->getSelectedIndex(),
        );
        $this->listWidget->setItems($items);
        $this->listWidget->setSelectedIndex($state->getSelectedIndex());
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
    }

    public function isOpen(): bool
    {
        return null !== $this->container;
    }

    /**
     * Build SelectListWidget item arrays from completion suggestions.
     *
     * Value is the suggestion index (string) so callers can map back
     * when needed.  The label is theme-coloured (accent when selected,
     * default when not) so the selected row is visibly distinct beyond
     * SelectListWidget's built-in bold alone.  Descriptions are rendered
     * in muted colour, matching ModelPickerController's description style.
     *
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

            $items[] = [
                'value' => (string) $i,
                'label' => $label,
                'description' => $description,
            ];
        }

        return $items;
    }
}
