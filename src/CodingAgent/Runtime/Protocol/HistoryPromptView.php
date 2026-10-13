<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol;

/**
 * One bounded human-prompt preview and its immutable anchor.
 */
final readonly class HistoryPromptView
{
    public function __construct(
        public int $turnNo,
        public string $promptText,
        public int $anchor,
    ) {
    }
}
