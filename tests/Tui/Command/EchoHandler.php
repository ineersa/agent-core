<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Command;

use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\TranscriptMessage;

/**
 * @internal Test-only handler that echoes back the command args.
 */
final readonly class EchoHandler implements SlashCommandHandler
{
    public function handle(SlashCommand $command): CommandResult
    {
        return new TranscriptMessage(
            'got args: ' . ($command->args === '' ? '(none)' : $command->args),
            'system',
        );
    }
}
