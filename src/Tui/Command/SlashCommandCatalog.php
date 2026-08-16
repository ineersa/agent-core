<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

use Ineersa\Tui\Command\Hotkey\HotkeyRegistry;

/**
 * Process-scoped slash command catalog.
 *
 * Owns command metadata, aliases, help/completion data, and default
 * handlers for commands whose handler objects are process-owned
 * (built-in stateless commands, extension commands, prompt-template
 * commands).  Per-session handler bindings live in the fresh
 * {@see SlashCommandRegistry} created for each TUI session; that
 * registry consults this catalog for metadata and default handlers.
 *
 * Extension seam: {@see register()} is called by TuiCommandRegistryAdapter
 * at any time during a session, and the catalog is shared, so commands
 * registered dynamically become visible immediately.
 */
final class SlashCommandCatalog
{
    /** @var array<string, SlashCommandHandler> canonical name → default handler */
    private array $handlers = [];

    /** @var array<string, CommandMetadata> canonical name → metadata */
    private array $metadata = [];

    /** @var array<string, string> alias → canonical name */
    private array $aliasMap = [];

    public function __construct(
        private readonly HotkeyRegistry $hotkeyRegistry = new HotkeyRegistry(),
    ) {
        // Built-in /help — metadata only; handle is built-in to execute()
        $this->addMetadata(
            new CommandMetadata(
                name: 'help',
                aliases: ['h', '?'],
                description: 'Show available commands and their descriptions',
                usage: '/help [command]',
            ),
        );

        // Built-in /hotkeys — metadata only; handle is built-in to execute()
        $this->addMetadata(
            new CommandMetadata(
                name: 'hotkeys',
                aliases: ['hk'],
                description: 'Show keyboard shortcuts grouped by context',
                usage: '/hotkeys',
            ),
        );

        // Built-in /clear
        $this->register(
            new CommandMetadata(
                name: 'clear',
                aliases: ['cls'],
                description: 'Clear the conversation transcript',
                usage: '/clear',
            ),
            new ClearScreenCommand(),
        );

        // Built-in /exit
        $this->register(
            new CommandMetadata(
                name: 'exit',
                aliases: ['quit', 'q'],
                description: 'Exit the TUI application',
                usage: '/exit',
            ),
            new ExitTuiCommand(),
        );
    }

    /**
     * Register a command with its metadata and default handler.
     *
     * @throws \InvalidArgumentException if the command name or any alias
     *                                   is already registered
     */
    public function register(CommandMetadata $metadata, SlashCommandHandler $handler): void
    {
        $this->assertNotColliding($metadata);
        $this->handlers[$metadata->name] = $handler;
        $this->addMetadata($metadata);
    }

    /**
     * Register metadata without a default handler.
     *
     * Used by built-in command registrars once per process; the
     * per-session registry binds the actual handler for each session.
     *
     * @throws \InvalidArgumentException if the command name or any alias
     *                                   is already registered
     */
    public function registerMetadata(CommandMetadata $metadata): void
    {
        $this->assertNotColliding($metadata);
        $this->addMetadata($metadata);
    }

    /**
     * Check whether a command name or alias is registered.
     */
    public function has(string $name): bool
    {
        return null !== $this->resolveName($name);
    }

    /**
     * Get metadata for a command by name or alias.
     *
     * @return CommandMetadata|null metadata, or null if not registered
     */
    public function getMetadata(string $name): ?CommandMetadata
    {
        $canonical = $this->resolveName($name);

        return null !== $canonical ? ($this->metadata[$canonical] ?? null) : null;
    }

    /**
     * Get all registered command metadata, sorted by command name.
     *
     * @return list<CommandMetadata>
     */
    public function allMetadata(): array
    {
        $all = array_values($this->metadata);
        usort($all, static fn (CommandMetadata $a, CommandMetadata $b) => strcmp($a->name, $b->name),
        );

        return $all;
    }

    /**
     * Get all metadata as a map of canonical name → metadata.
     *
     * @return array<string, CommandMetadata>
     */
    public function allMetadataMap(): array
    {
        return $this->metadata;
    }

    /**
     * Get the number of registered commands.
     */
    public function count(): int
    {
        return \count($this->metadata);
    }

    /**
     * Resolve a name or alias to its canonical command name.
     *
     * @return string|null the canonical name, or null if not found
     */
    public function resolveName(string $name): ?string
    {
        // Direct canonical name match
        if (isset($this->metadata[$name])) {
            return $name;
        }

        // Alias resolution
        if (isset($this->aliasMap[$name])) {
            return $this->aliasMap[$name];
        }

        return null;
    }

    /**
     * Get the process-owned default handler for a canonical command name.
     *
     * @return SlashCommandHandler|null handler, or null when the command
     *                                  has metadata only (built-in help/hotkeys or
     *                                  per-session-bound built-ins)
     */
    public function defaultHandler(string $canonical): ?SlashCommandHandler
    {
        return $this->handlers[$canonical] ?? null;
    }

    /**
     * The hotkey catalog backing the built-in /hotkeys command.
     */
    public function hotkeyRegistry(): HotkeyRegistry
    {
        return $this->hotkeyRegistry;
    }

    // ── Internal ────────────────────────────────────────────────────

    private function assertNotColliding(CommandMetadata $metadata): void
    {
        $name = $metadata->name;

        if (isset($this->metadata[$name])) {
            throw new \InvalidArgumentException("Command '{$name}' is already registered.");
        }

        foreach ($metadata->aliases as $alias) {
            if (isset($this->aliasMap[$alias])) {
                $existing = $this->aliasMap[$alias];
                throw new \InvalidArgumentException("Alias '{$alias}' is already registered for command '{$existing}'.");
            }
            // Also guard against aliases that collide with canonical names
            if (isset($this->metadata[$alias])) {
                throw new \InvalidArgumentException("Alias '{$alias}' conflicts with registered command name.");
            }
        }
    }

    /**
     * Register metadata without a handler (used for built-in /help).
     */
    private function addMetadata(CommandMetadata $metadata): void
    {
        $name = $metadata->name;
        $this->metadata[$name] = $metadata;
        foreach ($metadata->aliases as $alias) {
            $this->aliasMap[$alias] = $name;
        }
    }
}
