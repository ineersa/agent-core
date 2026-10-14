<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol;

/**
 * Sparse conversation history for TUI presentation.
 *
 * @param list<HistoryEntryView> $entries
 */
final readonly class HistoryView
{
    /**
     * @param list<HistoryEntryView> $entries
     */
    public function __construct(
        public array $entries,
        public int $selectedAnchor = 0,
        public ?int $olderBefore = null,
        public ?int $newerAfter = null,
        public string $selectedRole = 'user',
    ) {
    }
}
