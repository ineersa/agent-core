<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

use Ineersa\Tui\Command\Hotkey\HotkeyRegistry;

/**
 * Registry of slash commands with built-in help, lookup, and dispatch.
 *
 * Commands are registered with metadata (name, aliases, description,
 * usage) and a handler. The registry resolves aliases, dispatches
 * execution, and formats built-in /help from its own metadata.
 *
 * Unknown commands return a friendly TranscriptMessage rather than
 * throwing — the registry treats unknown input as user-facing, not as
 * a programming error.
 *
 * Extension seam: later tasks (AI/model, EDITOR-08 completion) call
 * {@see register()} to add new commands without modifying submission
 * routing.
 */
final class SlashCommandRegistry
{
    // ─── Column width caps (display columns) for /hotkeys table ───
    private const HOTKEYS_MAX_KEY_WIDTH = 30;
    private const HOTKEYS_MAX_ACTION_WIDTH = 35;
    private const HOTKEYS_MAX_DESC_WIDTH = 45;
    /** @var array<string, SlashCommandHandler> canonical name → handler */
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
     * Register a command with its metadata and handler.
     *
     * @throws \InvalidArgumentException if the command name or any alias
     *                                   is already registered
     */
    public function register(CommandMetadata $metadata, SlashCommandHandler $handler): void
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

        $this->handlers[$name] = $handler;
        $this->metadata[$name] = $metadata;

        foreach ($metadata->aliases as $alias) {
            $this->aliasMap[$alias] = $name;
        }
    }

    /**
     * Execute a slash command and return its result.
     *
     * Resolution order:
     *  1. If the name matches a registered alias, resolve to canonical name.
     *  2. If the canonical name has a registered handler, delegate to it.
     *  3. If the name is "help" and no custom handler overrides it, build help.
     *  4. Otherwise return a friendly "unknown command" TranscriptMessage.
     *
     * Step 3 allows registering a custom /help handler that overrides
     * the built-in behavior.
     */
    public function execute(SlashCommand $command): CommandResult
    {
        $canonical = $this->resolveName($command->name);

        // Custom handler takes precedence (allows overriding built-in help)
        if (null !== $canonical && isset($this->handlers[$canonical])) {
            $effectiveCommand = $command;

            // When the command does not declare that it accepts arguments,
            // silently strip any extra text so `/clear foo` behaves like
            // `/clear` and `/exit now` like `/exit` — avoiding misleading
            // "Unknown command" errors for built-in no-arg commands.
            $meta = $this->metadata[$canonical] ?? null;
            if (null !== $meta && !$meta->acceptsArguments && '' !== $command->args) {
                $effectiveCommand = new SlashCommand($command->name, '', $command->originalText);
            }

            return $this->handlers[$canonical]->handle($effectiveCommand);
        }

        // Built-in help (only if no custom handler registered)
        if ('help' === $canonical) {
            return $this->buildHelpMessage($command->args);
        }

        // Built-in hotkeys (reads from live HotkeyRegistry)
        if ('hotkeys' === $canonical) {
            return $this->buildHotkeysMessage();
        }

        // Unknown command → friendly typed result
        return new TranscriptMessage(
            \sprintf(
                'Unknown command: /%s. Type /help for available commands.',
                $command->name,
            ),
            'system',
            'muted',
        );
    }

    /**
     * Check whether a command name or alias is registered.
     */
    public function has(string $name): bool
    {
        $canonical = $this->resolveName($name);

        return null !== $canonical;
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
     * Set or replace the handler for an already-registered command.
     *
     * @throws \InvalidArgumentException if no command is registered with that name
     */
    public function setHandler(string $name, SlashCommandHandler $handler): void
    {
        $canonical = $this->resolveName($name);
        if (null === $canonical) {
            throw new \InvalidArgumentException("Cannot set handler: command '{$name}' is not registered.");
        }
        $this->handlers[$canonical] = $handler;
    }

    // ── Internal ────────────────────────────────────────────────────

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

    /**
     * Resolve a name or alias to its canonical command name.
     *
     * @return string|null the canonical name, or null if not found
     */
    private function resolveName(string $name): ?string
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
     * Build a TranscriptMessage with formatted help text.
     *
     * When args is empty, lists all commands with descriptions.
     * When args names a command, shows detailed help for that command.
     */
    private function buildHelpMessage(string $args): TranscriptMessage
    {
        if ('' !== $args) {
            return $this->buildSingleCommandHelp($args);
        }

        $lines = [
            'Available commands:',
            '',
        ];

        foreach ($this->allMetadata() as $meta) {
            $aliases = [] !== $meta->aliases
                ? ' ('.implode(', ', $meta->aliases).')'
                : '';
            $lines[] = \sprintf(
                '  /%-20s %s',
                $meta->name.$aliases,
                $meta->description,
            );
        }

        $lines[] = '';
        $lines[] = 'Type /help <command> for more details.';

        return new TranscriptMessage(
            implode("\n", $lines),
            'system',
        );
    }

    /**
     * Build help for a single command name.
     */
    private function buildSingleCommandHelp(string $name): TranscriptMessage
    {
        $normalized = trim($name);
        $meta = $this->getMetadata($normalized);

        // Unknown command name in `/help <name>` — fall back to the
        // general help listing instead of reporting an error, so
        // accidental `/help 123` (or any unrecognised arg) simply
        // displays available commands.
        if (null === $meta) {
            return $this->buildHelpMessage('');
        }

        $lines = [
            \sprintf('Command: /%s', $meta->name),
            '',
        ];

        if ('' !== $meta->description) {
            $lines[] = $meta->description;
            $lines[] = '';
        }

        if ([] !== $meta->aliases) {
            $lines[] = \sprintf('Aliases: %s', implode(', ', $meta->aliases));
            $lines[] = '';
        }

        if ('' !== $meta->usage) {
            $lines[] = \sprintf('Usage: %s', $meta->usage);
            $lines[] = '';
        }

        return new TranscriptMessage(
            implode("\n", $lines),
            'system',
        );
    }

    /**
     * Build a TranscriptMessage with a box-drawing hotkeys table.
     *
     * Reads the live {@see HotkeyRegistry} and renders one grouped
     * table per context (Global, Editor, Completion, History, Model)
     * with three columns: Keys, Action, Description.
     *
     * Uses Unicode box-drawing characters (┌┬┐├┼┤└┴┘│─) and display-
     * width-aware padding so ↑/↓ and CJK characters align correctly.
     *
     * Section names are plain text — colored section headers would
     * require per-fragment styling in the transcript renderer
     * (planned for RENDER-02 / rich transcript blocks).
     *
     * Editor bindings reflect the active EditorWidget keybindings
     * rather than stale defaults.
     */
    private function buildHotkeysMessage(): TranscriptMessage
    {
        $groups = $this->hotkeyRegistry->grouped();

        if ([] === $groups) {
            return new TranscriptMessage(
                'No hotkey hints registered. This is a bug — hotkeys should be populated during TUI startup.',
                'system',
            );
        }

        $lines = ['Keyboard shortcuts', ''];

        foreach ($groups as $context => $bindings) {
            $lines[] = '  '.$context;
            $lines[] = '';

            foreach ($this->buildContextTable($bindings) as $row) {
                $lines[] = $row;
            }
            $lines[] = '';
        }

        $lines[] = 'App shortcuts (Ctrl+C, Ctrl+D) are global and cannot be remapped.';
        $lines[] = 'Editor bindings reflect the current keymap and may differ from defaults.';

        return new TranscriptMessage(
            implode("\n", $lines),
            'system',
        );
    }

    /**
     * Build a box-drawing table for one context's hotkey bindings.
     *
     * Returns an array of lines (without trailing newlines).
     *
     * @param list<Hotkey\HotkeyBindingDTO> $bindings
     *
     * @return list<string>
     */
    private function buildContextTable(array $bindings): array
    {
        // Build rows: [keysStr, action, description]
        $rows = [];
        $hasDesc = false;

        foreach ($bindings as $b) {
            $keysStr = implode(', ', array_map(
                static fn (string $k): string => self::formatKeyDisplay($k),
                $b->keys,
            ));
            $desc = $b->description;
            if ('' !== $desc) {
                $hasDesc = true;
            }
            $rows[] = [$keysStr, $b->action, $desc];
        }

        // Compute display widths from content (capped)
        $keyW = 0;
        $actW = 0;
        $descW = 0;

        foreach ($rows as [$k, $a, $d]) {
            $keyW = max($keyW, mb_strwidth($k));
            $actW = max($actW, mb_strwidth($a));
            if ($hasDesc) {
                $descW = max($descW, mb_strwidth($d));
            }
        }

        $keyW = min($keyW, self::HOTKEYS_MAX_KEY_WIDTH);
        $actW = min($actW, self::HOTKEYS_MAX_ACTION_WIDTH);
        if ($hasDesc) {
            $descW = min($descW, self::HOTKEYS_MAX_DESC_WIDTH);
        }

        // Headers must fit within the computed or capped widths
        $keyHeader = 'Keys';
        $actHeader = 'Action';
        $descHeader = 'Description';
        $keyW = max($keyW, mb_strwidth($keyHeader));
        $actW = max($actW, mb_strwidth($actHeader));
        if ($hasDesc) {
            $descW = max($descW, mb_strwidth($descHeader));
        }

        $result = [];
        $h = '─';

        if ($hasDesc) {
            // Three-column table
            $result[] = \sprintf('  ┌%s┬%s┬%s┐',
                str_repeat($h, $keyW + 2),
                str_repeat($h, $actW + 2),
                str_repeat($h, $descW + 2));
            $result[] = \sprintf('  │ %s │ %s │ %s │',
                self::padDisplayWidth($keyHeader, $keyW),
                self::padDisplayWidth($actHeader, $actW),
                self::padDisplayWidth($descHeader, $descW));
            $result[] = \sprintf('  ├%s┼%s┼%s┤',
                str_repeat($h, $keyW + 2),
                str_repeat($h, $actW + 2),
                str_repeat($h, $descW + 2));

            foreach ($rows as [$k, $a, $d]) {
                $result[] = \sprintf('  │ %s │ %s │ %s │',
                    self::truncPadDisplayWidth($k, $keyW),
                    self::truncPadDisplayWidth($a, $actW),
                    self::truncPadDisplayWidth($d, $descW));
            }

            $result[] = \sprintf('  └%s┴%s┴%s┘',
                str_repeat($h, $keyW + 2),
                str_repeat($h, $actW + 2),
                str_repeat($h, $descW + 2));
        } else {
            // Two-column table (no descriptions at all)
            $result[] = \sprintf('  ┌%s┬%s┐',
                str_repeat($h, $keyW + 2),
                str_repeat($h, $actW + 2));
            $result[] = \sprintf('  │ %s │ %s │',
                self::padDisplayWidth($keyHeader, $keyW),
                self::padDisplayWidth($actHeader, $actW));
            $result[] = \sprintf('  ├%s┼%s┤',
                str_repeat($h, $keyW + 2),
                str_repeat($h, $actW + 2));

            foreach ($rows as [$k, $a]) {
                $result[] = \sprintf('  │ %s │ %s │',
                    self::truncPadDisplayWidth($k, $keyW),
                    self::truncPadDisplayWidth($a, $actW));
            }

            $result[] = \sprintf('  └%s┴%s┘',
                str_repeat($h, $keyW + 2),
                str_repeat($h, $actW + 2));
        }

        return $result;
    }

    /**
     * Pad a string to the given display-column width using spaces.
     *
     * Uses mb_strwidth so multi-byte Unicode characters (e.g. ↑, ↓)
     * are correctly measured in terminal columns rather than bytes.
     */
    private static function padDisplayWidth(string $text, int $targetWidth): string
    {
        $current = mb_strwidth($text);
        if ($current >= $targetWidth) {
            return $text;
        }

        return $text.str_repeat(' ', $targetWidth - $current);
    }

    /**
     * Truncate a string to fit within target display width, then pad.
     *
     * Strings longer than the target are truncated with a single
     * '…' (U+2026, one display column) appended.
     */
    private static function truncPadDisplayWidth(string $text, int $targetWidth): string
    {
        $current = mb_strwidth($text);
        if ($current <= $targetWidth) {
            return self::padDisplayWidth($text, $targetWidth);
        }

        // Walk backward to find a cut point that fits with '…' appended.
        $maxLen = mb_strlen($text);
        for ($i = $maxLen; $i > 0; --$i) {
            $prefix = mb_substr($text, 0, $i);
            if (mb_strwidth($prefix) + 1 <= $targetWidth) {
                return self::padDisplayWidth($prefix.'…', $targetWidth);
            }
        }

        // Extremely narrow column: just ellipsis
        return self::padDisplayWidth('…', $targetWidth);
    }

    /**
     * Format a key identifier string for display.
     *
     * Converts lowercase identifiers like 'ctrl+j' to 'Ctrl+J',
     * 'shift+enter' to 'Shift+Enter', 'up' to '↑', etc.
     */
    private static function formatKeyDisplay(string $keyId): string
    {
        $normalized = strtolower(trim($keyId));
        $parts = explode('+', $normalized);
        $baseKey = array_pop($parts);

        $modifiers = array_map(
            static fn (string $m): string => match ($m) {
                'ctrl' => 'Ctrl',
                'shift' => 'Shift',
                'alt' => 'Alt',
                default => ucfirst($m),
            },
            $parts,
        );

        $formattedKey = match ($baseKey) {
            'up' => '↑',
            'down' => '↓',
            'left' => '←',
            'right' => '→',
            'enter' => 'Enter',
            'escape' => 'Esc',
            'tab' => 'Tab',
            'space' => 'Space',
            'backspace' => 'Bksp',
            'delete' => 'Del',
            'home' => 'Home',
            'end' => 'End',
            'page_up' => 'PgUp',
            'page_down' => 'PgDn',
            default => ucfirst($baseKey),
        };

        if ([] === $modifiers) {
            return $formattedKey;
        }

        return implode('+', array_merge($modifiers, [$formattedKey]));
    }
}
