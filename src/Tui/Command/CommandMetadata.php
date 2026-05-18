<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

/**
 * Metadata for a slash command: identity, help text, and discoverability.
 *
 * Used by the SlashCommandRegistry for help output and future completion
 * (EDITOR-08). The name is the canonical command name (lowercase, no slash
 * prefix). Aliases are alternative names that resolve to the same command.
 */
final readonly class CommandMetadata
{
    /**
     * @param string       $name        Canonical command name (lowercase, e.g. "help")
     * @param list<string> $aliases     Alternative names (e.g. ["h", "?"])
     * @param string       $description One-line summary for /help listing
     * @param string       $usage       Usage example (e.g. "/help [command]")
     */
    public function __construct(
        public string $name,
        public array $aliases = [],
        public string $description = '',
        public string $usage = '',
    ) {
    }
}
