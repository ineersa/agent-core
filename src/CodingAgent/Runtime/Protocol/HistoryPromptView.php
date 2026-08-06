<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol;

/**
 * One retained history turn for TUI presentation (user or internal assistant).
 */
final readonly class HistoryPromptView
{
    public function __construct(
        public int $turnNo,
        public string $title,
        public string $displayRole,
        public string $promptText = '',
        public bool $isPosition = false,
    ) {
    }
}
