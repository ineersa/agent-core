<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Command;

/**
 * Definition of a slash command registered by an extension.
 *
 * Immutable DTO carrying the metadata the TUI needs to register a
 * slash command: name, aliases, description, usage, and whether it
 * accepts arguments.
 *
 * @see ExtensionCommandHandlerInterface
 */
final readonly class CommandDefinitionDTO
{
    /**
     * @param string       $name             Canonical command name (lowercase, e.g. "tasks")
     * @param list<string> $aliases          Alternative names (e.g. ["t"])
     * @param string       $description      One-line summary for /help listing
     * @param string       $usage            Usage example (e.g. "/tasks [filter]")
     * @param bool         $acceptsArguments Whether the command consumes arguments
     */
    public function __construct(
        public string $name,
        public array $aliases = [],
        public string $description = '',
        public string $usage = '',
        public bool $acceptsArguments = false,
    ) {
    }
}
