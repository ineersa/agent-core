<?php

declare(strict_types=1);

namespace Ineersa\Tui\Completion;

/**
 * Pure state machine for completion menu lifecycle.
 *
 * Tracks whether the menu is open and which suggestions are displayed.
 * Selection index ownership lives on the mounted {@see \Symfony\Component\Tui\Widget\SelectListWidget};
 * this state only stores the suggestion list for accept/replacement mapping.
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
    }

    /**
     * Close the completion menu without modifying editor text.
     */
    public function close(): void
    {
        $this->open = false;
        $this->suggestions = [];
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    /**
     * Resolve a suggestion from SelectListWidget item value (string index).
     */
    public function suggestionByValue(string $value): ?CompletionSuggestion
    {
        if (!$this->open || [] === $this->suggestions) {
            return null;
        }

        if (!ctype_digit($value)) {
            return null;
        }

        return $this->suggestions[(int) $value] ?? null;
    }

    /**
     * Accept the suggestion currently selected in the SelectListWidget.
     *
     * Does NOT close the menu — the caller should call {@see close()}
     * separately after applying the suggestion.
     *
     * @return CompletionSuggestion|null the accepted suggestion, or null
     *                                   if the menu is not open / value unknown
     */
    public function acceptSelected(?string $selectedValue): ?CompletionSuggestion
    {
        if (null === $selectedValue) {
            return null;
        }

        return $this->suggestionByValue($selectedValue);
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
}
