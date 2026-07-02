<?php

declare(strict_types=1);

namespace Ineersa\Tui\Extension;

use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;

final readonly class RewindSlashCommandDecorator implements SlashCommandHandler
{
    public function __construct(
        private SlashCommandHandler $inner,
        private string $sessionId,
    ) {
    }

    public function handle(SlashCommand $command): CommandResult
    {
        if ($this->inner instanceof ExtensionSlashCommandHandler) {
            $this->inner->setSessionId($this->sessionId);
        }

        return $this->inner->handle($command);
    }
}
