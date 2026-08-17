<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

use Ineersa\Tui\Command\Hotkey\HotkeyTableData;

/**
 * Session-scoped slash command registry with built-in help and dispatch.
 *
 * Created fresh for each TUI session.  Command metadata, aliases, help,
 * and completion data live in the process-scoped {@see SlashCommandCatalog};
 * this registry owns only the session-local handler bindings and the
 * execution rules (session handler → catalog default handler → built-in
 * help/hotkeys → unknown command).
 *
 * Registrars bind their per-session handlers via {@see bind()} during
 * {@see \Ineersa\Tui\Listener\TuiListenerRegistrar::register()}.  Extension
 * commands registered dynamically on the shared catalog are resolved at
 * execute time, so they are visible immediately.
 *
 * Unknown commands return a friendly TranscriptMessage rather than
 * throwing — the registry treats unknown input as user-facing, not as
 * a programming error.
 */
final class SlashCommandRegistry
{
    /** @var array<string, SlashCommandHandler> canonical name → session handler */
    private array $handlers = [];

    public function __construct(
        private readonly SlashCommandCatalog $catalog,
    ) {
    }

    /**
     * Bind the session-local handler for an already-registered command.
     *
     * @throws \InvalidArgumentException if no command is registered with that name
     */
    public function bind(string $name, SlashCommandHandler $handler): void
    {
        $canonical = $this->catalog->resolveName($name);
        if (null === $canonical) {
            throw new \InvalidArgumentException("Cannot bind handler: command '{$name}' is not registered.");
        }
        $this->handlers[$canonical] = $handler;
    }

    /**
     * Execute a slash command and return its result.
     *
     * Resolution order:
     *  1. If the name matches a registered alias, resolve to canonical name.
     *  2. If the canonical name has a session-local handler, delegate to it.
     *  3. Otherwise fall back to the catalog's default handler (extension,
     *     prompt-template, or built-in stateless commands).
     *  4. If the name is "help" and no custom handler overrides it, build help.
     *  5. Otherwise return a friendly "unknown command" TranscriptMessage.
     *
     * Step 4 allows registering a custom /help handler that overrides
     * the built-in behavior.
     */
    public function execute(SlashCommand $command): CommandResult
    {
        $canonical = $this->catalog->resolveName($command->name);

        $handler = null !== $canonical
            ? ($this->handlers[$canonical] ?? $this->catalog->defaultHandler($canonical))
            : null;

        if (null !== $handler) {
            $effectiveCommand = $command;

            // When the command does not declare that it accepts arguments,
            // silently strip any extra text so `/clear foo` behaves like
            // `/clear` and `/exit now` like `/exit` — avoiding misleading
            // "Unknown command" errors for built-in no-arg commands.
            $meta = $this->catalog->getMetadata($canonical);
            if (null !== $meta && !$meta->acceptsArguments && '' !== $command->args) {
                $effectiveCommand = new SlashCommand($command->name, '', $command->originalText);
            }

            return $handler->handle($effectiveCommand);
        }

        // Built-in help (only if no custom handler registered)
        if ('help' === $canonical) {
            return $this->buildHelpMessage($command->args);
        }

        // Built-in hotkeys (reads from live HotkeyRegistry)
        if ('hotkeys' === $canonical) {
            return new HotkeyTableData($this->catalog->hotkeyRegistry()->grouped());
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

    // ── Internal ────────────────────────────────────────────────────

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

        foreach ($this->catalog->allMetadata() as $meta) {
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
        $meta = $this->catalog->getMetadata($normalized);

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
}
