<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;

/**
 * Session-scoped list of user prompts for Up/Down history navigation.
 *
 * Lifecycle:
 * - {@see seedFrom()} resets and rebuilds from the projected transcript on each
 *   session start, resume, or switch (called from {@see PromptHistoryListener::register()}).
 * - {@see append()} grows the list when the user submits a real prompt or bang
 *   command ({@see SubmitListener}).
 * - Rewind is intentionally a no-op: no rewind-specific rebuild; the list may
 *   stay ahead of a rewound transcript until the next {@see seedFrom()}.
 *
 * Because {@see seedFrom()} resets wholesale and {@see append()} only appends
 * during a session, a {@see PromptHistoryNavigator} cursor into {@see prompts()}
 * stays valid for the lifetime of that session iteration.
 */
final class PromptHistory
{
    /** @var list<string> */
    private array $prompts = [];

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
}
