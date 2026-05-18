<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Command;

use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;

/**
 * @internal Test-only handler that always returns NoOp.
 */
final readonly class NoOpTestHandler implements SlashCommandHandler
{
    public function handle(SlashCommand $command): CommandResult
    {
        return new NoOp();
    }
}
