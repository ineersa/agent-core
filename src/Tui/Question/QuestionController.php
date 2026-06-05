<?php

declare(strict_types=1);

namespace Ineersa\Tui\Question;

use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Widget\WidgetPlacementEnum;
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
    private ?TuiRuntimeContext $context = null;

    public function __construct(
        private readonly QuestionCoordinator $coordinator,
    ) {
    }

    /**
     * Set the per-run TUI references that are only available at
     * listener registration time.
     */
    public function setRuntimeRefs(TuiRuntimeContext $context): void
    {
        $this->context = $context;
    }

    /**
     * Open the interactive question overlay.
     *
     * Builds and mounts a ContainerWidget with header + prompt for Text
     * kind, or header + SelectListWidget for interactive kinds.
     *
     * @param WidgetPlacementEnum $placement Controls where the container is
     *                                       added in the TUI widget tree
     *                                       (used by future dynamic slot code)
     */
    public function open(QuestionRequest $request, WidgetPlacementEnum $placement = WidgetPlacementEnum::AboveEditor): void
    {
        if (null === $this->context) {
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
            $this->context?->tui->remove($this->container);
            $this->container = null;
        }
        $this->listWidget = null;
        $this->isOpen = false;
        $this->context?->screen->setStatus('action', null);
        $this->context?->screen->refresh();
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
            QuestionKind::Text => 'Human input required',
            QuestionKind::Confirm => 'Confirmation required',
            QuestionKind::Choice => 'Choose an option',
            QuestionKind::Approval => 'Approval requested',
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

        $hintText = $request->secret
            ? '[answer will be hidden, type and press Enter]'
            : '[type answer and press Enter]';
        $hint = new TextWidget(text: $hintText, truncate: true);
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

            $answer = '__other__' === $value
                ? $this->context->screen->editorText()
                : $value;

            $this->coordinator->answer($answer);
            $this->close();
        });

        $this->listWidget->onCancel(function (CancelEvent $event): void {
            $this->coordinator->cancel();
            $this->close();
        });

        $this->container->add($this->listWidget);
    }

    /**
     * Mount the container into the TUI widget tree and request focus.
     */
    private function mount(QuestionRequest $request): void
    {
        $this->context->tui->add($this->container);
        $this->context->screen->setStatus('action', "\u{26A0} Question pending");

        if (QuestionKind::Text !== $request->kind) {
            $this->context->tui->setFocus($this->listWidget);
        }

        $this->context->tui->requestRender(true);
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
                ['value' => 'yes', 'label' => 'Yes'],
                ['value' => 'no', 'label' => 'No'],
            ],
            QuestionKind::Choice => array_map(
                static fn (QuestionOption $opt): array => [
                    'value' => $opt->label,
                    'label' => $opt->label,
                    'description' => $opt->description,
                ],
                $request->choices,
            ),
            QuestionKind::Approval => $this->approvalItems($request),
        };

        if ($request->allowOther) {
            $items[] = ['value' => '__other__', 'label' => 'Type your answer'];
        }

        return $items;
    }

    /**
     * Build Approval-choice items, preferring the schema enum when
     * available (e.g. SafeGuard's ["Allow once", "Always allow", "Deny"]).
     *
     * Falls back to generic Approve/Reject when no enum is provided.
     *
     * @return list<array{value: string, label: string}>
     */
    private function approvalItems(QuestionRequest $request): array
    {
        $enum = $request->schema['enum'] ?? null;

        if (\is_array($enum) && [] !== $enum) {
            return array_map(
                static fn (string $label): array => [
                    'value' => $label,
                    'label' => $label,
                ],
                array_values($enum),
            );
        }

        return [
            ['value' => 'approve', 'label' => 'Approve'],
            ['value' => 'reject', 'label' => 'Reject'],
        ];
    }
}
