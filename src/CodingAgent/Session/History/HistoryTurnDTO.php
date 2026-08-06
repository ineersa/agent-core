<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

/**
 * One retained turn in ordered linear history.
 */
final readonly class HistoryTurnDTO
{
    public function __construct(
        public int $turnNo,
        public string $title,
        public string $displayRole,
        public string $promptText = '',
    ) {
    }
}
