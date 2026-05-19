<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

/**
 * The submitted text is a regular prompt — not a command.
 */
final readonly class NormalPromptCommand implements CommandParseResult
{
    public function __construct(
        public string $text,
    ) {
    }

    public function originalText(): string
    {
        return $this->text;
    }
}
