<?php

declare(strict_types=1);

namespace Ineersa\Tui\Question;

use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Manages the interactive question overlay lifecycle.
 *
 * Opens a question-specific overlay on the TUI:
 *  - Text kind: renders a TextWidget banner with prompt/hint above the editor
 *  - Confirm/Choice/Approval kinds: opens an interactive SelectListWidget
 *    with arrow-key navigation and Enter to select / Esc to cancel.
 *
 * When allowOther is true on the request, a "Type your answer" option is
 * appended to the select list, allowing free-form input from the editor.
 *
 * Follows the same pattern as ModelPickerController — widgets are created
 * and destroyed per invocation, never permanently mounted in ChatScreen.
 */
final class QuestionController
{
    private ?SelectListWidget $listWidget = null;
    private ?ContainerWidget $container = null;
    private bool $isOpen = false;
    private bool $awaitingFreeForm = false;
    private ?QuestionRequest $activeRequest = null;
    private ?ChatScreen $screen = null;

    public function __construct(
        private readonly QuestionCoordinator $coordinator,
        private readonly QuestionOverlayPromptRenderer $promptRenderer = new QuestionOverlayPromptRenderer(),
    ) {
    }

    /**
     * Set the per-run TUI references that are only available at
     * listener registration time.
     */
    /**
     * @param TuiRuntimeContext $_context Unused; kept for caller compatibility
     */
    public function setRuntimeRefs(TuiRuntimeContext $_context, ChatScreen $screen): void
    {
        $this->screen = $screen;
    }

    /**
     * Open the interactive question overlay.
     *
     * Builds and mounts a ContainerWidget with header + prompt for Text
     * kind, or header + SelectListWidget for interactive kinds.
     */
    public function open(QuestionRequest $request): void
    {
        if (null === $this->screen) {
            throw new \LogicException('setRuntimeRefs() must be called before open()');
        }

        if ($this->isOpen) {
            return;
        }

        $this->awaitingFreeForm = false;
        $this->activeRequest = $request;
        $this->container = new ContainerWidget();
        $this->addHeader($request);

        if (QuestionKind::Text === $request->kind) {
            $this->addTextBanner($request);
        } else {
            $this->addSelectList($request);
        }

        $this->mount($request);
    }

    /**
     * Remove the question overlay and clear status.
     */
    public function close(): void
    {
        if (null !== $this->container) {
            $this->screen?->removeOverlay($this->container);
            $this->container = null;
        }
        $this->listWidget = null;
        $this->isOpen = false;
        $this->awaitingFreeForm = false;
        $this->screen?->setStatus('action', null);
        // Targeted overlay removal; full refresh() still redraws the screen — deeper compositor follow-up if flicker persists.
        $this->screen?->refresh();
    }

    /**
     * Whether the question overlay is currently open.
     */
    public function isOpen(): bool
    {
        return $this->isOpen;
    }

    /**
     * True when the overlay was dismissed for free-form input (__other__ escape
     * hatch). TickPollListener's per-tick re-open guard checks this flag so it
     * does not rebuild the select overlay while the user types.
     */
    public function isAwaitingFreeForm(): bool
    {
        return $this->awaitingFreeForm;
    }

    /**
     * Restore the select overlay after a free-form dismiss (__other__ escape
     * hatch). Called by CancelListener when ESC is pressed during free-form
     * typing — instead of cancelling the run, ESC returns the user to the
     * option list. A second ESC from the list then triggers the widget's
     * onCancel → coordinator->cancel() → 'Cancelled by user' reaches the model.
     *
     * Safe no-op when not awaiting free-form or when no request is available.
     */
    public function restoreFromFreeForm(): void
    {
        if (!$this->awaitingFreeForm) {
            return;
        }

        $this->awaitingFreeForm = false;

        if (null !== $this->activeRequest && null !== $this->screen) {
            $this->open($this->activeRequest);
        }
    }

    // ── Private helpers ──

    /**
     * Dismiss the select overlay for free-form editor input (the __other__
     * escape hatch).
     *
     * Sets awaitingFreeForm=true so TickPollListener's per-tick re-open guard
     * does NOT rebuild the select overlay on the next tick (the active request
     * remains unanswered so actionRequired() is still true — without this flag
     * the guard would see !isOpen() and re-open with a fresh SelectListWidget
     * at selectedIndex=0, resetting the selection).
     *
     * Focus is moved to the editor BEFORE close() so the SelectListWidget is
     * not the focused widget when it detaches (avoids FocusManager::remove()
     * reassigning focus at detach time).
     *
     * SubmitListener intercepts the next editor submission (Enter) while
     * actionRequired() is true and routes the typed text through
     * coordinator->answer(), which dequeues the request and clears
     * awaitingFreeForm via close().
     */
    private function dismissToEditor(): void
    {
        $this->screen?->setFocus($this->screen->editorWidget());
        $this->close();
        $this->awaitingFreeForm = true;
        $this->screen?->setStatus('action', 'Type your answer and press Enter');
    }

    /**
     * Add a kind-appropriate header to the container.
     */
    private function addHeader(QuestionRequest $request): void
    {
        $headerText = $request->header ?? match ($request->kind) {
            QuestionKind::Text => "\u{1F4DD} Human input required",
            QuestionKind::Confirm => "\u{2753} Confirmation required",
            QuestionKind::Choice => "\u{1F4CB} Choose an option",
        };
        $theme = $this->screen?->theme();
        if (null !== $theme) {
            $this->container->add($this->promptRenderer->buildIndentedHeader($headerText, $theme));
        } else {
            $this->container->add(new TextWidget(text: '  '.$headerText, truncate: false));
        }
    }

    /**
     * Add TextWidget banner (prompt + hint) for Text kind questions.
     */
    private function addTextBanner(QuestionRequest $request): void
    {
        // Repeat the active prompt in the overlay so the user does not have to look
        // back at the transcript while typing. Wrap to multiple lines (truncate: false)
        // instead of the old single-line ellipsis truncation.
        $theme = $this->screen?->theme();
        if (null === $theme) {
            $this->container->add(new TextWidget(text: $request->prompt, truncate: false));
            $this->container->add(new TextWidget(text: '[type answer and press Enter]', truncate: false));

            return;
        }

        $this->container->add($this->promptRenderer->buildPromptWidget($request->prompt, $theme));
        $this->container->add($this->promptRenderer->buildIndentedHint('[type answer and press Enter]', $theme));
    }

    /**
     * Build and wire a SelectListWidget for interactive question kinds.
     *
     * Creates the widget with items from buildItems(), attaches Enter/ESCAPE
     * callbacks, and adds it to the container.
     */
    private function addSelectList(QuestionRequest $request): void
    {
        // Interactive kinds: transcript may not carry the same prompt; keep a short prompt line without truncation.
        $theme = $this->screen?->theme();
        if (null !== $theme) {
            $this->container->add($this->promptRenderer->buildPromptWidget($request->prompt, $theme));
        } else {
            $this->container->add(new TextWidget(text: $request->prompt, truncate: false));
        }

        $items = $this->buildItems($request);
        $items = $this->styleConfirmItems($items, $request->kind);
        $kb = new Keybindings([
            'select_up' => [Key::UP],
            'select_down' => [Key::DOWN],
            'select_page_up' => [Key::PAGE_UP],
            'select_page_down' => [Key::PAGE_DOWN],
            'select_confirm' => [Key::ENTER],
            'select_cancel' => [Key::ESCAPE, Key::ctrl('c')],
        ]);

        $this->listWidget = new SelectListWidget(
            items: $items,
            maxVisible: 10,
            keybindings: $kb,
        );

        $this->listWidget->onSelect(function (SelectEvent $event): void {
            $item = $event->getItem();
            $value = $item['value'];

            if ('__other__' === $value) {
                // Dismiss the select overlay for free-form editor input.
                // Defers answering to the next editor Enter via SubmitListener.
                $this->dismissToEditor();

                return;
            }

            try {
                $this->coordinator->answer($value);
            } finally {
                $this->close();
            }
        });

        $this->listWidget->onCancel(function (CancelEvent $event): void {
            try {
                $this->coordinator->cancel();
            } finally {
                $this->close();
            }
        });

        $this->container->add($this->listWidget);
    }

    /**
     * Apply theme colors to confirm Yes/No items.
     *
     * Only applies styling when the question kind is Confirm, since the
     * yes/no value guards would incorrectly color Choice items whose
     * labels happen to be 'yes' or 'no'.
     *
     * Uses success/green for Yes and error/red for No so the
     * affirmative and negative choices are visually distinct.
     *
     * @param list<array{value: string, label: string, description?: string}> $items
     * @param QuestionKind                                                    $kind  The question kind — styling only applies to Confirm
     *
     * @return list<array{value: string, label: string, description?: string}>
     */
    private function styleConfirmItems(array $items, QuestionKind $kind): array
    {
        if (QuestionKind::Confirm !== $kind) {
            return $items;
        }

        if (null === $this->screen) {
            return $items;
        }

        $theme = $this->screen->theme();

        foreach ($items as $k => $item) {
            if ('yes' === $item['value']) {
                $items[$k]['label'] = $theme->color(ThemeColorEnum::Success, $item['label']);
            } elseif ('no' === $item['value']) {
                $items[$k]['label'] = $theme->color(ThemeColorEnum::Error, $item['label']);
            }
        }

        return $items;
    }

    /**
     * Mount the container into the TUI widget tree and request focus.
     */
    private function mount(QuestionRequest $request): void
    {
        // Insert the overlay above the editor so it renders above
        // the editor area in the single-column layout:
        //   question overlay → editor-separator → editor → …
        $this->screen->insertOverlayBeforeEditor($this->container);
        $this->screen->setStatus('action', "\u{26A0} Question pending");

        if (null !== $this->listWidget) {
            $this->screen->setFocus($this->listWidget);
        }

        // Non-forced render: forced full repaint on mount contributed to visible flicker/scroll churn.
        $this->screen->requestRender(false);
        $this->isOpen = true;
    }

    /**
     * Build SelectListWidget items for the given request kind.
     *
     * When allowOther is true, a "Type your answer" option is appended
     * as the last item.
     *
     * @return list<array{value: string, label: string, description?: string}>
     */
    private function buildItems(QuestionRequest $request): array
    {
        // Text kind uses a TextWidget banner, never SelectListWidget.
        if (QuestionKind::Text === $request->kind) {
            return [];
        }

        $items = match ($request->kind) {
            QuestionKind::Confirm => [
                ['value' => 'yes', 'label' => "\u{2713} Yes"],
                ['value' => 'no', 'label' => "\u{2717} No"],
            ],
            QuestionKind::Choice => array_map(
                static fn (QuestionOption $opt): array => [
                    'value' => $opt->label,
                    'label' => $opt->label,
                    'description' => $opt->description,
                ],
                $request->choices,
            ),
        };

        // The escape hatch only renders for Choice — Confirm's Yes/No are
        // exhaustive for a boolean schema (free-form text would be silently
        // coerced to boolean, making the hatch a trap). Text kind uses a banner.
        if ($request->allowOther && QuestionKind::Choice === $request->kind) {
            $items[] = ['value' => '__other__', 'label' => 'Type your answer'];
        }

        return $items;
    }
}
