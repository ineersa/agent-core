<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Session\Repair\SessionRepairService;
use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Runtime\TuiSessionState;

final class RepairCommandHandler implements SlashCommandHandler
{
    public function __construct(
        private readonly TuiSessionState $state,
        private readonly SessionRepairService $repairService,
    ) {
    }

    public function handle(SlashCommand $command): CommandResult
    {
        $sessionId = $this->state->sessionId;
        if ('' === $sessionId) {
            return new TranscriptMessage('No active session — start or resume a session first.', 'system', 'muted');
        }

        try {
            $result = $this->repairService->repair($sessionId, true);
        } catch (\Throwable $e) {
            return new TranscriptMessage('Session repair failed: '.$e->getMessage(), 'error');
        }

        return new TranscriptMessage($result['message']);
    }
}
