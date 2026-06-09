<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Picker\SessionPickerController;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;

/**
 * Handles the /resume slash command.
 *
 * Without arguments: opens the interactive session picker so the
 * user can browse recent sessions and resume one.
 *
 * With a session ID: validates that the session exists and
 * requests a switch via the switch service.  Returns an error
 * message when the session is not found or the ID is malformed.
 */
final class ResumeSessionCommandHandler implements SlashCommandHandler
{
    public function __construct(
        private readonly TuiSessionSwitchServiceInterface $switch,
        private readonly HatfieldSessionStore $sessionStore,
        private readonly SessionPickerController $pickerController,
    ) {
    }

    public function handle(SlashCommand $command): CommandResult
    {
        $sessionId = $command->args;

        if ('' === $sessionId) {
            $this->pickerController->open();

            return new NoOp();
        }

        // Session IDs are numeric strings; reject malformed input before
        // hitting the store to produce a clear error regardless of store
        // behaviour.
        if (!ctype_digit($sessionId)) {
            return new TranscriptMessage(
                \sprintf('Invalid session id: %s', $sessionId),
                'error',
            );
        }

        if (!$this->sessionStore->exists($sessionId)) {
            return new TranscriptMessage(
                \sprintf('Session not found: %s', $sessionId),
                'error',
            );
        }

        $this->switch->requestResume($sessionId);

        return new NoOp();
    }
}
