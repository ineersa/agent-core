<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Runtime\PromptHistoryInterface;

/**
 * Session-scoped prompt list and Up/Down navigation cursor for history recall.
 *
 * Lifecycle:
 * - {@see seedFrom()} resets the list and navigation cursor, rebuilding from the
 *   projected transcript on each session start, resume, or switch (called from
 *   {@see PromptHistoryListener::register()}).
 * - {@see append()} grows the list when the user submits a real prompt or bang
 *   command ({@see SubmitListener}).
 * - After conversation rewind, {@see RuntimeEventPoller} reseeds via
 *   {@see seedFrom()} from the active projected transcript so Up/Down cannot
 *   recall abandoned bang or prompt lines.
 *
 * Navigation uses O(1) indexing into {@see prompts()} — no transcript scan per
 * keypress. {@see previous()} walks toward older prompts; {@see next()} toward
 * newer; past newest returns null (caller clears editor and exits navigation).
 */
final class PromptHistory implements PromptHistoryInterface
{
    /** @var list<string> */
    private array $prompts = [];

    private ?int $cursor = null;

    /**
     * @return list<string>
     */
    public function prompts(): array
    {
        return $this->prompts;
    }

    /**
     * Reset and rebuild from transcript UserMessage blocks (order preserved).
     *
     * @param list<TranscriptBlock> $transcript
     */
    public function seedFrom(array $transcript): void
    {
        $this->prompts = [];
        $this->cursor = null;

        foreach ($transcript as $block) {
            if (TranscriptBlockKindEnum::UserMessage === $block->kind) {
                $this->prompts[] = $block->text;
            }
        }
    }

    public function append(string $text): void
    {
        $this->prompts[] = $text;
    }

    public function previous(): ?string
    {
        if ([] === $this->prompts) {
            return null;
        }

        $target = ($this->cursor ?? \count($this->prompts)) - 1;
        if ($target < 0) {
            return null;
        }

        $this->cursor = $target;

        return $this->prompts[$target];
    }

    public function next(): ?string
    {
        if (null === $this->cursor) {
            return null;
        }

        $target = $this->cursor + 1;
        if ($target >= \count($this->prompts)) {
            $this->cursor = null;

            return null;
        }

        $this->cursor = $target;

        return $this->prompts[$target];
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
