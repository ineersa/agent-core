<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

/**
 * Routes submitted editor text to either the runtime (normal prompts)
 * or the local slash command registry.
 *
 * Stateless, pure domain logic — no TUI/listener/screen dependencies.
 * The caller is responsible for applying {@see CommandResult} effects.
 *
 * Return semantics:
 *  - {@see CommandResult} — a local command was executed; the caller
 *    should apply the typed effect (TranscriptMessage, ClearTranscript,
 *    ExitApplication, NoOp, StatusUpdate, DispatchRuntime).
 *  - `null` — a normal prompt; the caller should send the text to the
 *    runtime as before.
 */
final readonly class SubmissionRouter
{
    public function __construct(
        private CommandParser $parser,
        private SlashCommandRegistry $registry,
    ) {
    }

    /**
     * Parse and route submitted editor text.
     *
     * @return CommandResult|null a command result, or null for a normal prompt
     */
    public function route(string $submittedText): ?CommandResult
    {
        $parseResult = $this->parser->parse($submittedText);

        // Slash command → execute via registry
        if ($parseResult instanceof SlashCommand) {
            return $this->registry->execute($parseResult);
        }

        // Shell command → friendly message (EDITOR-11 not yet implemented)
        if ($parseResult instanceof ShellCommand) {
            return new TranscriptMessage(
                'Shell commands (!/!!) are not yet supported. Type /help for available commands.',
                'system',
                'muted',
            );
        }

        // Normal prompt (including empty) → send to runtime
        return null;
    }
}
