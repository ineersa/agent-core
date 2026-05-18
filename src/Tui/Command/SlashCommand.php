<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

/**
 * A slash command, e.g. "/help" or "/exit --force".
 */
final readonly class SlashCommand implements CommandParseResult
{
    /**
     * @param string $name         Command name (lowercased first word after "/")
     * @param string $args         Everything after the command name, trimmed
     * @param string $originalText The full trimmed submitted text
     */
    public function __construct(
        public string $name,
        public string $args,
        public string $originalText,
    ) {}

    public function originalText(): string
    {
        return $this->originalText;
    }
}
