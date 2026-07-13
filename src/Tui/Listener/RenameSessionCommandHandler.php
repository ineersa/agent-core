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

/**
 * Handles the /rename slash command.
 *
 * With a session ID and a new name: validates the session exists,
 * updates its name via HatfieldSessionStore::updateMetadata(), and
 * returns a success message with the sanitised name.
 *
 * With only a session ID (no name): returns an error with a hint
 * showing the correct usage for that specific session.
 *
 * Without arguments: opens the interactive session picker in rename
 * mode. Selecting a session inserts "/rename <id> " into the prompt
 * editor so the user can type a new name — it does NOT execute a
 * rename immediately.
 */
final class RenameSessionCommandHandler implements SlashCommandHandler
{
    public function __construct(
        private readonly HatfieldSessionStore $sessionStore,
        private readonly SessionPickerController $pickerController,
    ) {
    }

    public function handle(SlashCommand $command): CommandResult
    {
        $args = $command->args;

        // ── No arguments → open rename picker ──
        if ('' === $args) {
            $this->pickerController->openForRenameCommand();

            return new NoOp();
        }

        // ── Parse session ID and name ──
        $parts = preg_split('/\s+/', trim($args), 2);
        $sessionId = $parts[0];
        $newName = $parts[1] ?? '';

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

        // Missing/blank new name → error with concrete hint
        if ('' === trim($newName)) {
            return new TranscriptMessage(
                \sprintf('Provide a name. Example: `/rename %s My session name`', $sessionId),
                'error',
            );
        }

        // ── Rename ──
        $this->sessionStore->updateMetadata($sessionId, ['name' => $newName]);

        // Load the persisted name to confirm what was stored (the store may
        // sanitise/truncate the requested name).
        $session = $this->sessionStore->findSession($sessionId);
        $persistedName = null !== $session ? $session->name : trim($newName);

        return new TranscriptMessage(
            \sprintf('Session %s renamed to "%s"', $sessionId, $persistedName),
            'system',
        );
    }
}
