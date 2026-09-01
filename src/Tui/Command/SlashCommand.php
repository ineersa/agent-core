<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

/**
 * Slash command submitted via a leading "/".
 */
final readonly class SlashCommand implements CommandParseResult
{
    public function __construct(
        public string $name,
        public string $args,
        public string $originalText,
    ) {
    }
}
