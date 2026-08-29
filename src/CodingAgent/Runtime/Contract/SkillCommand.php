<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Lightweight DTO for a skill slash command exposed to the TUI.
 *
 * Carries only the slash-command name (`skill:<skill-name>`) and description —
 * enough for SlashCommandCatalog registration. The TUI never sees skill bodies,
 * paths, or frontmatter.
 */
final readonly class SkillCommand
{
    public function __construct(
        /** Lowercase slash command name including the skill: prefix (e.g. skill:castor). */
        public string $name,
        /** Short description for autocomplete and /help. */
        public string $description,
    ) {
    }
}
