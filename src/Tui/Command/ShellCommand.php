<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

/**
 * Shell command submitted via a leading "!".
 *
 * Only single "!" is supported (EDITOR-11 MVP).
 */
final readonly class ShellCommand implements CommandParseResult
{
    public function __construct(
        public string $command,
        public string $originalText,
    ) {
    }
}
