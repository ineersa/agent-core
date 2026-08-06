<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol;

/**
 * Sparse human-prompt history for TUI presentation.
 *
 * @param list<HistoryPromptView> $prompts
 * @param int                     $positionTurnNo explicit tip; 0 = before first / empty
 */
final readonly class HistoryView
{
    /**
     * @param list<HistoryPromptView> $prompts
     */
    public function __construct(
        public array $prompts,
        public int $positionTurnNo,
    ) {
    }
}
