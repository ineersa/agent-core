<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

/**
 * Requests the application to exit cleanly.
 */
final readonly class ExitTuiCommand implements SlashCommandHandler
{
    public function handle(SlashCommand $command): CommandResult
    {
        return new ExitApplication();
    }
}
