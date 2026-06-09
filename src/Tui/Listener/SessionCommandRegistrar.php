<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Picker\SessionPickerController;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Registers /new and /resume slash commands in the TUI.
 *
 * On registration (called once per TUI iteration):
 *  - Wires picker controller per-run references.
 *  - Registers /new and /resume commands idempotently so
 *    repeated registrations after session rebuilds do not
 *    cause duplicate-command errors.
 *
 * Command handlers are created fresh each registration with
 * references from the current TUI iteration (switch service,
 * session store, picker controller).
 */
final class SessionCommandRegistrar implements TuiListenerRegistrar
{
    public function __construct(
        private readonly SlashCommandRegistry $commandRegistry,
        private readonly SessionPickerController $pickerController,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $tui = $context->tui;
        $screen = $context->screen;
        $state = $context->state;

        // Wire the picker controller with per-iteration references
        $this->pickerController->setRuntimeRefs($tui, $screen, $state);

        // ── Register /new slash command (idempotent) ──
        $newHandler = new NewSessionCommandHandler($context->switch);
        if ($this->commandRegistry->has('new')) {
            $this->commandRegistry->setHandler('new', $newHandler);
        } else {
            $this->commandRegistry->register(
                new CommandMetadata(
                    name: 'new',
                    description: 'Start a new session',
                    usage: '/new',
                    acceptsArguments: false,
                ),
                $newHandler,
            );
        }

        // ── Register /resume slash command (idempotent) ──
        $resumeHandler = new ResumeSessionCommandHandler(
            $context->switch,
            $context->sessionStore,
            $this->pickerController,
        );
        if ($this->commandRegistry->has('resume')) {
            $this->commandRegistry->setHandler('resume', $resumeHandler);
        } else {
            $this->commandRegistry->register(
                new CommandMetadata(
                    name: 'resume',
                    aliases: ['r'],
                    description: 'Resume or switch to another session',
                    usage: '/resume [session id]',
                    acceptsArguments: true,
                ),
                $resumeHandler,
            );
        }
    }
}
