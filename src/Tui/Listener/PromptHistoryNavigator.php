<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

/**
 * Cursor-only navigation over {@see PromptHistory::prompts()}.
 *
 * O(1) indexing into the cached prompt list — no transcript scan on each
 * keypress. Shell-like behaviour:
 *  - Up   (previous): walk toward older prompts.
 *  - Down (next):     walk toward newer prompts; past newest returns null
 *                     (caller clears editor and exits navigation).
 *
 * Placed in the TuiListener layer alongside {@see PromptHistory}.
 */
final class PromptHistoryNavigator
{
    private ?int $cursor = null;

    public function __construct(
        private readonly PromptHistory $history,
    ) {
    }

    public function previous(): ?string
    {
        $prompts = $this->history->prompts();
        if ([] === $prompts) {
            return null;
        }

        $target = ($this->cursor ?? \count($prompts)) - 1;
        if ($target < 0) {
            return null;
        }

        $this->cursor = $target;

        return $prompts[$target];
    }

    public function next(): ?string
    {
        if (null === $this->cursor) {
            return null;
        }

        $prompts = $this->history->prompts();
        $target = $this->cursor + 1;
        if ($target >= \count($prompts)) {
            $this->cursor = null;

            return null;
        }

        $this->cursor = $target;

        return $prompts[$target];
    }

    public function isNavigating(): bool
    {
        return null !== $this->cursor;
    }

    public function exitNavigation(): void
    {
        $this->cursor = null;
    }

    /**
     * @internal
     */
    public function cursor(): ?int
    {
        return $this->cursor;
    }
}
