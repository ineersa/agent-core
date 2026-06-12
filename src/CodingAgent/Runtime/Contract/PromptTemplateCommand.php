<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Lightweight DTO for a prompt-template command exposed to the TUI.
 *
 * Carries only the name and description — enough for slash-command
 * registration in SlashCommandRegistry. The TUI never sees template
 * content, paths, or argument parsing.
 */
final readonly class PromptTemplateCommand
{
    public function __construct(
        /** Lowercase template name (used as slash command name). */
        public string $name,
        /** Short description for autocomplete and /help. */
        public string $description,
    ) {
    }
}
