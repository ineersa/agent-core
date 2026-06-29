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
    private ?ChatScreen $screen = null;

    public function __construct(
        private readonly QuestionCoordinator $coordinator,
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
        $this->screen?->setStatus('action', null);
        $this->screen?->refresh();
    }

    /**
     * Whether the question overlay is currently open.
     */
    public function isOpen(): bool
    {
        return $this->isOpen;
    }

    // ── Private helpers ──

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
        $header = new TextWidget(text: $headerText, truncate: true);
        $this->container->add($header);
    }

    /**
     * Add TextWidget banner (prompt + hint) for Text kind questions.
     */
    private function addTextBanner(QuestionRequest $request): void
    {
        $prompt = new TextWidget(text: $request->prompt, truncate: true);
        $this->container->add($prompt);

        $hint = new TextWidget(text: '[type answer and press Enter]', truncate: true);
        $this->container->add($hint);
    }

    /**
     * Build and wire a SelectListWidget for interactive question kinds.
     *
     * Creates the widget with items from buildItems(), attaches Enter/ESCAPE
     * callbacks, and adds it to the container.
     */
    private function addSelectList(QuestionRequest $request): void
    {
        $prompt = new TextWidget(text: $request->prompt, truncate: true);
        $this->container->add($prompt);

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
                // Close the overlay without answering. SubmitListener will
                // intercept the next editor submission and route the typed
                // text through coordinator->answer() via its question
                // interception path.
                $this->close();
                $this->screen?->setStatus('action', 'Type your answer and press Enter');

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

        $this->screen->requestRender(true);
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

        if ($request->allowOther) {
            $items[] = ['value' => '__other__', 'label' => 'Type your answer'];
        }

        return $items;
    }
}
