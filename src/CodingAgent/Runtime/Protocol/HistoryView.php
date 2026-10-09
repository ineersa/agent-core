<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol;

/**
 * Sparse human-prompt history for TUI presentation.
 *
 * @param list<HistoryPromptView> $prompts
 */
final readonly class HistoryView
{
    /**
     * @param list<HistoryPromptView> $prompts
     */
    public function __construct(
        public array $prompts,
        public int $selectedAnchor = 0,
        public ?int $olderBefore = null,
        public ?int $newerAfter = null,
    ) {
    }
}
