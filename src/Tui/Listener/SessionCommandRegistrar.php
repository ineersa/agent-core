<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Extension\Session\ExtensionSessionEventPublisher;
use Ineersa\Tui\Command\BuiltInSlashCommands;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Registers /new, /resume, /rename, and /reload slash commands in the TUI.
 *
 * Command metadata is static data in {@see BuiltInSlashCommands}; each
 * session iteration binds fresh handlers wired to the session's picker
 * controller and switch service.
 */
final class SessionCommandRegistrar implements TuiListenerRegistrar
{
    public function __construct(private readonly ExtensionSessionEventPublisher $extensionEvents)
    {
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
            $this->extensionEvents,
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
