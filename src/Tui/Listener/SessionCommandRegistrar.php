<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Registers /new, /resume, /rename, and /reload slash commands in the TUI.
 *
 * Command metadata is registered once per process via
 * {@see registerCatalog()}; each session iteration binds fresh handlers
 * wired to the session's picker controller and switch service.
 */
final class SessionCommandRegistrar implements TuiListenerRegistrar, SlashCommandCatalogRegistrar
{
    public function registerCatalog(SlashCommandCatalog $catalog): void
    {
        $catalog->registerMetadata(new CommandMetadata(
            name: 'new',
            description: 'Start a new session',
            usage: '/new',
            acceptsArguments: false,
        ));
        $catalog->registerMetadata(new CommandMetadata(
            name: 'resume',
            aliases: ['r'],
            description: 'Resume or switch to another session',
            usage: '/resume [session id]',
            acceptsArguments: true,
        ));
        $catalog->registerMetadata(new CommandMetadata(
            name: 'rename',
            description: 'Rename a session',
            usage: '/rename [session id] [new name]',
            acceptsArguments: true,
        ));
        $catalog->registerMetadata(new CommandMetadata(
            name: 'reload',
            description: 'Reload settings and restart the session',
            usage: '/reload',
            acceptsArguments: false,
        ));
    }

    public function register(TuiRuntimeContext $context): void
    {
        $registry = $context->sessionServices->commandRegistry;
        $pickerController = $context->sessionServices->sessionPicker;

        // ── Bind /new slash command ──
        $registry->bind('new', new NewSessionCommandHandler($context->switch));

        // ── Bind /resume slash command ──
        $registry->bind('resume', new ResumeSessionCommandHandler(
            $context->switch,
            $context->sessionStore,
            $pickerController,
        ));

        // ── Bind /rename slash command ──
        $registry->bind('rename', new RenameSessionCommandHandler(
            $context->sessionStore,
            $pickerController,
        ));

        // ── Bind /reload slash command ──
        $registry->bind('reload', new ReloadCommandHandler(
            $context->switch,
            $context->state,
            $context->screen,
            $context->sessionServices->questionCoordinator,
        ));
    }
}
