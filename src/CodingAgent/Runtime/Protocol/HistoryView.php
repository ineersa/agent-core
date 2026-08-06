<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol;

/**
 * Ordered retained history for TUI presentation.
 *
 * @param list<HistoryPromptView> $turns
 */
final readonly class HistoryView
{
    /**
     * @param list<HistoryPromptView> $turns
     */
    public function __construct(
        public string $runId,
        public array $turns,
        public ?int $positionTurnNo,
    ) {
    }
}
