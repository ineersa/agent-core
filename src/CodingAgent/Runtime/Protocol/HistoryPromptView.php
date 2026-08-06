<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol;

/**
 * One selectable human prompt for TUI presentation.
 */
final readonly class HistoryPromptView
{
    public function __construct(
        public int $turnNo,
        public string $promptText,
    ) {
    }
}
