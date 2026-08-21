<?php

declare(strict_types=1);

namespace Ineersa\Tui\Question;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Ansi\TextWrapper;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SelectionChangeEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\SelectListWidget;

/**
 * Question-overlay selectable list that wraps option labels instead of truncating.
 *
 * Symfony {@see SelectListWidget} always calls {@see AnsiUtils::truncateToWidth()}
 * and assumes one physical row per item. ask_human Choice overlays need the full
 * label visible, so this question-only widget keeps logical item selection while
 * wrapping labels (and optional descriptions) with {@see TextWrapper::wrapTextWithAnsi()}.
 *
 * Extends {@see SelectListWidget} only so {@see SelectEvent}/{@see SelectionChangeEvent}
 * retain their constructor type; render/input are fully overridden and filtering is
 * intentionally omitted (ask_human does not search).
 */
final class QuestionChoiceListWidget extends SelectListWidget
{
    private const string SELECTED_PREFIX = '→ ';
    private const string UNSELECTED_PREFIX = '  ';

    /** @var list<array{value: string, label: string, description?: string}> */
    private array $choiceItems;

    private int $choiceSelectedIndex = 0;
    private bool $choiceSelected = false;
    private int $choiceMaxVisible;

    /**
     * @param list<array{value: string, label: string, description?: string}> $items
     */
    public function __construct(
        array $items,
        int $maxVisible = 5,
        ?Keybindings $keybindings = null,
    ) {
        parent::__construct($items, $maxVisible, $keybindings);
        $this->choiceItems = array_values($items);
        $this->choiceMaxVisible = max(1, $maxVisible);
    }

    /**
     * @param array<array{value: string, label: string}> $items
     *
     * @return $this
     */
    public function setItems(array $items): static
    {
        parent::setItems($items);
        /** @var list<array{value: string, label: string, description?: string}> $normalized */
        $normalized = array_values($items);
        $this->choiceItems = $normalized;
        $this->choiceSelectedIndex = 0;
        $this->invalidate();

        return $this;
    }

    /**
     * @return $this
     */
    public function setSelectedIndex(int $index): static
    {
        if ([] === $this->choiceItems) {
            $this->choiceSelectedIndex = 0;
            parent::setSelectedIndex(0);

            return $this;
        }

        $index = max(0, min($index, \count($this->choiceItems) - 1));
        if ($this->choiceSelectedIndex !== $index) {
            $this->choiceSelectedIndex = $index;
            parent::setSelectedIndex($index);
            $this->invalidate();
        }

        return $this;
    }

    /**
     * @return array{value: string, label: string, description?: string}|null
     */
    public function getSelectedItem(): ?array
    {
        return $this->choiceItems[$this->choiceSelectedIndex] ?? null;
    }

    public function wasSelected(): bool
    {
        return $this->choiceSelected;
    }

    public function handleInput(string $data): void
    {
        // Parent KeybindingsTrait::$onInput is private; QuestionController does not use onInput.
        $kb = $this->getKeybindings();

        if ([] !== $this->choiceItems) {
            if ($kb->matches($data, 'select_up')) {
                $this->choiceSelectedIndex = 0 === $this->choiceSelectedIndex
                    ? \count($this->choiceItems) - 1
                    : $this->choiceSelectedIndex - 1;
                parent::setSelectedIndex($this->choiceSelectedIndex);
                $this->notifySelectionChange();

                return;
            }

            if ($kb->matches($data, 'select_down')) {
                $this->choiceSelectedIndex = $this->choiceSelectedIndex === \count($this->choiceItems) - 1
                    ? 0
                    : $this->choiceSelectedIndex + 1;
                parent::setSelectedIndex($this->choiceSelectedIndex);
                $this->notifySelectionChange();

                return;
            }

            if ($kb->matches($data, 'select_page_up')) {
                $this->choiceSelectedIndex = max(0, $this->choiceSelectedIndex - $this->choiceMaxVisible);
                parent::setSelectedIndex($this->choiceSelectedIndex);
                $this->notifySelectionChange();

                return;
            }

            if ($kb->matches($data, 'select_page_down')) {
                $this->choiceSelectedIndex = min(
                    \count($this->choiceItems) - 1,
                    $this->choiceSelectedIndex + $this->choiceMaxVisible,
                );
                parent::setSelectedIndex($this->choiceSelectedIndex);
                $this->notifySelectionChange();

                return;
            }

            if ($kb->matches($data, 'select_confirm')) {
                $this->confirmSelection();

                return;
            }
        }

        if ($kb->matches($data, 'select_cancel')) {
            $this->choiceSelected = false;
            $this->dispatch(new CancelEvent($this));
        }
    }

    /** @return string[] */
    public function render(RenderContext $context): array
    {
        $columns = max(1, $context->getColumns());
        $lines = [];

        if ([] === $this->choiceItems) {
            $lines[] = $this->applyElement('no-match', '  No matching items');

            return $lines;
        }

        $startIndex = max(
            0,
            min(
                $this->choiceSelectedIndex - (int) floor($this->choiceMaxVisible / 2),
                \count($this->choiceItems) - $this->choiceMaxVisible,
            ),
        );
        $endIndex = min($startIndex + $this->choiceMaxVisible, \count($this->choiceItems));

        $prefixWidth = AnsiUtils::visibleWidth(self::SELECTED_PREFIX);
        $labelWidth = max(1, $columns - $prefixWidth);
        $continuationIndent = str_repeat(' ', $prefixWidth);

        for ($i = $startIndex; $i < $endIndex; ++$i) {
            $item = $this->choiceItems[$i];
            $isSelected = $i === $this->choiceSelectedIndex;
            $prefix = $isSelected ? self::SELECTED_PREFIX : self::UNSELECTED_PREFIX;
            $labelLines = TextWrapper::wrapTextWithAnsi((string) $item['label'], $labelWidth);
            if ([] === $labelLines) {
                $labelLines = [''];
            }

            foreach ($labelLines as $lineIndex => $labelLine) {
                $rowPrefix = 0 === $lineIndex ? $prefix : $continuationIndent;
                $row = $rowPrefix.$labelLine;
                $lines[] = $isSelected
                    ? $this->resolveElement('selected')->apply($row)
                    : $this->applyElement('label', $row);
            }

            $description = isset($item['description']) ? $this->normalizeDescription((string) $item['description']) : null;
            if (null !== $description && '' !== $description) {
                $descriptionLines = TextWrapper::wrapTextWithAnsi($description, $labelWidth);
                foreach ($descriptionLines as $descriptionLine) {
                    $row = $continuationIndent.$descriptionLine;
                    $lines[] = $isSelected
                        ? $this->resolveElement('selected')->apply($row)
                        : $this->applyElement('description', $row);
                }
            }
        }

        if ($startIndex > 0 || $endIndex < \count($this->choiceItems)) {
            $scrollText = \sprintf('  (%d/%d)', $this->choiceSelectedIndex + 1, \count($this->choiceItems));
            $scrollWidth = max(1, $columns - 2);
            $lines[] = $this->applyElement(
                'scroll-info',
                AnsiUtils::truncateToWidth($scrollText, $scrollWidth, ''),
            );
        }

        return $lines;
    }

    /**
     * @return array<string, string[]>
     */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'select_up' => [Key::UP],
            'select_down' => [Key::DOWN],
            'select_page_up' => [Key::PAGE_UP],
            'select_page_down' => [Key::PAGE_DOWN],
            'select_confirm' => [Key::ENTER],
            'select_cancel' => [Key::ESCAPE, 'ctrl+c'],
        ];
    }

    private function normalizeDescription(string $description): string
    {
        return trim(preg_replace('/[\r\n]+/', ' ', $description) ?? $description);
    }

    private function confirmSelection(): void
    {
        $this->choiceSelected = true;
        $selectedItem = $this->choiceItems[$this->choiceSelectedIndex] ?? null;
        if (null !== $selectedItem) {
            $this->dispatch(new SelectEvent($this, $selectedItem));
        }
    }

    private function notifySelectionChange(): void
    {
        $this->invalidate();
        $selectedItem = $this->choiceItems[$this->choiceSelectedIndex] ?? null;
        if (null !== $selectedItem) {
            $this->dispatch(new SelectionChangeEvent($this, $selectedItem));
        }
    }
}
