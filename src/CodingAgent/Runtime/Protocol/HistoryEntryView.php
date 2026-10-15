<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol;

/**
 * One bounded history entry preview and its immutable anchor.
 */
final readonly class HistoryEntryView
{
    public function __construct(
        public int $turnNo,
        public string $text,
        public int $anchor,
        public string $role = 'user',
    ) {
    }
}
