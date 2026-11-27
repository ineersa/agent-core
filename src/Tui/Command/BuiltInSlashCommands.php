<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

/**
 * Metadata of the built-in slash commands whose handlers are bound per
 * session.
 *
 * Kept as static, dependency-free data so {@see SlashCommandCatalog} can
 * register every built-in name and alias at construction without building
 * any service. The registrars in Ineersa\Tui\Listener bind the matching
 * handlers in each session's {@see SlashCommandRegistry}.
 */
final class BuiltInSlashCommands
{
    /**
     * @return list<CommandMetadata>
     */
    public static function metadata(): array
    {
        return [
            new CommandMetadata(
                name: 'new',
                description: 'Start a new session',
                usage: '/new',
                acceptsArguments: false,
            ),
            new CommandMetadata(
                name: 'resume',
                aliases: ['r'],
                description: 'Resume or switch to another session',
                usage: '/resume [session id]',
                acceptsArguments: true,
            ),
            new CommandMetadata(
                name: 'rename',
                description: 'Rename a session',
                usage: '/rename [session id] [new name]',
                acceptsArguments: true,
            ),
            new CommandMetadata(
                name: 'reload',
                description: 'Reload settings and restart the session',
                usage: '/reload',
                acceptsArguments: false,
            ),
            new CommandMetadata(
                name: 'copy',
                aliases: ['cp'],
                description: 'Copy the last model output to the clipboard',
                usage: '/copy',
            ),
            new CommandMetadata(
                name: 'compact',
                aliases: ['cmp'],
                description: 'Compact the conversation to reduce token usage',
                usage: '/compact [custom instructions]',
                acceptsArguments: true,
            ),
            new CommandMetadata(
                name: 'repair',
                aliases: [],
                description: 'Repair stale cancellation for the active session',
                usage: '/repair',
                acceptsArguments: false,
            ),
            new CommandMetadata(
                name: 'agents-live',
                description: 'Open interactive live view for a subagent',
                usage: '/agents-live',
                acceptsArguments: false,
            ),
            new CommandMetadata(
                name: 'agents-main',
                aliases: ['main'],
                description: 'Return from subagent live view to the main session',
                usage: '/agents-main',
                acceptsArguments: false,
            ),
            new CommandMetadata(
                name: 'model',
                aliases: ['m'],
                description: 'Select the active AI model',
                usage: '/model [provider/modelname]',
                acceptsArguments: true,
            ),
            new CommandMetadata(
                name: 'model-favourites',
                aliases: ['model-favourite'],
                description: 'Manage favourite AI models',
                usage: '/model-favourites [provider/modelname]',
                acceptsArguments: true,
            ),
            new CommandMetadata(
                name: 'mcp',
                description: 'List MCP servers and statuses; `/mcp reconnect` reconnects all',
                usage: '/mcp [reconnect]',
                acceptsArguments: true,
            ),
            new CommandMetadata(
                name: 'usage',
                description: 'Show ChatGPT and z.ai quota status plus session usage',
                usage: '/usage',
                acceptsArguments: false,
            ),
            new CommandMetadata(
                name: 'history',
                description: 'Show user prompts and final answers; Enter to select position, Esc to close',
                usage: '/history',
                acceptsArguments: false,
            ),
            new CommandMetadata(
                name: 'settings-show',
                description: 'Show effective settings with source and descriptions',
                usage: '/settings-show [group-or-path]',
                acceptsArguments: true,
            ),
            new CommandMetadata(
                name: 'bg',
                description: 'Manage background Bash and code-mode work; Ctrl+\\ for agents and forks',
                usage: '/bg',
                acceptsArguments: false,
            ),
            new CommandMetadata(
                name: 'export',
                aliases: ['exp'],
                description: 'Export the current session transcript to a file',
                usage: '/export [path]',
                acceptsArguments: true,
            ),
            new CommandMetadata(
                name: 'goal',
                description: 'Show, set, edit or clear the session goal',
                usage: '/goal [objective | edit | clear | cancel]',
                acceptsArguments: true,
            ),
        ];
    }
}
