<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

/**
 * Clears all entries from the conversation transcript.
 */
final readonly class ClearScreenCommand implements SlashCommandHandler
{
    public function handle(SlashCommand $command): CommandResult
    {
        return new ClearTranscript();
    }
}
