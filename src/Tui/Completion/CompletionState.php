<?php

declare(strict_types=1);

namespace Ineersa\Tui\Completion;

/**
 * Pure state machine for completion menu lifecycle.
 *
 * Tracks whether the menu is open, which suggestions are displayed,
 * and the currently selected index.  Up/Down navigation wraps around
 * the suggestion list.
 *
 * Closing via {@see close()} does not mutate editor text — the caller
 * (typically a listener) is responsible for text replacement when a
 * suggestion is accepted.
 */
final class CompletionState
{
    private bool $open = false;

    /** @var list<CompletionSuggestion> */
    private array $suggestions = [];

    private int $selectedIndex = 0;

    /**
     * Open the completion menu with the given suggestions.
     *
     * If the list is empty, the menu remains closed.
     *
     * @param list<CompletionSuggestion> $suggestions
     */
    public function open(array $suggestions): void
    {
        if ([] === $suggestions) {
            $this->close();

            return;
        }

        $this->open = true;
        $this->suggestions = $suggestions;
        $this->selectedIndex = 0;
    }

    /**
     * Close the completion menu without modifying editor text.
     */
    public function close(): void
    {
        $this->open = false;
        $this->suggestions = [];
        $this->selectedIndex = 0;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    /**
     * The currently highlighted suggestion, or null when the menu
     * is closed or the list is empty.
     */
    public function selected(): ?CompletionSuggestion
    {
        if (!$this->open || [] === $this->suggestions) {
            return null;
        }

        return $this->suggestions[$this->selectedIndex] ?? null;
    }

    /**
     * Move selection to the next suggestion, wrapping around.
     */
    public function moveNext(): void
    {
        if (!$this->open || [] === $this->suggestions) {
            return;
        }

        $count = \count($this->suggestions);
        $this->selectedIndex = ($this->selectedIndex + 1) % $count;
    }

    /**
     * Move selection to the previous suggestion, wrapping around.
     */
    public function movePrevious(): void
    {
        if (!$this->open || [] === $this->suggestions) {
            return;
        }

        $count = \count($this->suggestions);
        $this->selectedIndex = ($this->selectedIndex - 1 + $count) % $count;
    }

    /**
     * Accept the currently selected suggestion.
     *
     * Does NOT close the menu — the caller should call {@see close()}
     * separately after applying the suggestion.
     *
     * @return CompletionSuggestion|null the accepted suggestion, or null
     *                                   if the menu is not open
     */
    public function acceptSelected(): ?CompletionSuggestion
    {
        return $this->selected();
    }

    /**
     * Current suggestion list (for rendering).
     *
     * @return list<CompletionSuggestion>
     */
    public function getSuggestions(): array
    {
        return $this->suggestions;
    }

    /**
     * Current selected index (for rendering).
     */
    public function getSelectedIndex(): int
    {
        return $this->selectedIndex;
    }
}
