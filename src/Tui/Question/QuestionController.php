<?php

declare(strict_types=1);

namespace Ineersa\Tui\Question;

use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Widget\WidgetPlacementEnum;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Tui;
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

    private ?Tui $tui = null;
    private ?ChatScreen $screen = null;
    private ?QuestionCoordinator $coordinator = null;

    /**
     * Set the per-run TUI references that are only available at
     * listener registration time.
     */
    public function setRuntimeRefs(Tui $tui, ChatScreen $screen, QuestionCoordinator $coordinator): void
    {
        $this->tui = $tui;
        $this->screen = $screen;
        $this->coordinator = $coordinator;
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
        if ($this->isOpen || null === $this->tui || null === $this->screen || null === $this->coordinator) {
            return;
        }

        $tui = $this->tui;
        $screen = $this->screen;
        $coordinator = $this->coordinator;

        $this->container = new ContainerWidget();

        // ── Header — kind-appropriate title ──
        $headerText = $request->header ?? match ($request->kind) {
            QuestionKind::Text => 'Human input required',
            QuestionKind::Confirm => 'Confirmation required',
            QuestionKind::Choice => 'Choose an option',
            QuestionKind::Approval => 'Approval requested',
        };
        $header = new TextWidget(text: $headerText, truncate: true);
        $this->container->add($header);

        if (QuestionKind::Text === $request->kind) {
            // ── Text kind: banner only, user types in editor ──
            $prompt = new TextWidget(text: $request->prompt, truncate: true);
            $this->container->add($prompt);

            $hintText = $request->secret
                ? '[answer will be hidden, type and press Enter]'
                : '[type answer and press Enter]';
            $hint = new TextWidget(text: $hintText, truncate: true);
            $this->container->add($hint);
        } else {
            // ── Interactive kinds: SelectListWidget ──
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

            // ── Enter → answer, close ──
            $onSelectController = $this;
            $this->listWidget->onSelect(static function (SelectEvent $event) use (
                $coordinator, $screen, $onSelectController,
            ): void {
                $item = $event->getItem();
                $value = $item['value'];

                $answer = '__other__' === $value
                    ? $screen->editorText()
                    : $value;

                $coordinator->answer($answer);
                $onSelectController->close();
            });

            // ── Escape / Ctrl+C → cancel, close ──
            $onCancelController = $this;
            $this->listWidget->onCancel(static function (CancelEvent $event) use (
                $coordinator, $onCancelController,
            ): void {
                $coordinator->cancel();
                $onCancelController->close();
            });

            $this->container->add($this->listWidget);
        }

        // ── Mount and focus ──
        $tui->add($this->container);
        $screen->setStatus('action', "\u{26A0} Question pending");

        if (QuestionKind::Text !== $request->kind) {
            $tui->setFocus($this->listWidget);
        }

        $tui->requestRender(true);
        $this->isOpen = true;
    }

    /**
     * Remove the question overlay and clear status.
     */
    public function close(): void
    {
        if (null !== $this->container) {
            $this->tui?->remove($this->container);
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
            QuestionKind::Approval => [
                ['value' => 'approve', 'label' => 'Approve'],
                ['value' => 'reject', 'label' => 'Reject'],
            ],
            default => [],
        };

        if ($request->allowOther) {
            $items[] = ['value' => '__other__', 'label' => 'Type your answer'];
        }

        return $items;
    }
}
