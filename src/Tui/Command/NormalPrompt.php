<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

/**
 * The submitted text is a regular prompt — not a command.
 */
final readonly class NormalPrompt implements CommandParseResult
{
    public function __construct(
        public string $text,
    ) {}

    public function originalText(): string
    {
        return $this->text;
    }
}
