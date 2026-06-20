<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Command;

use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\TranscriptMessage;

/**
 * @internal test-only handler that returns a fixed transcript message
 */
final readonly class FixedMessageTestHandler implements SlashCommandHandler
{
    public function __construct(
        private string $message,
    ) {
    }

    public function handle(SlashCommand $command): CommandResult
    {
        return new TranscriptMessage($this->message, 'system');
    }
}
