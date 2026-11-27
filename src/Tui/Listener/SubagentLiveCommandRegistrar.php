<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\BuiltInSlashCommands;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Registers /agents-live and /agents-main slash commands in the TUI.
 *
 * Command metadata is static data in {@see BuiltInSlashCommands}; each
 * session binds fresh handlers wired to the session's subagent live picker
 * controller, question coordinator, and question controller.
 */
final class SubagentLiveCommandRegistrar implements TuiListenerRegistrar
{
    public function register(TuiRuntimeContext $context): void
    {
        $services = $context->sessionServices;
        $client = $context->client;
        $state = $context->state;
        $screen = $context->screen;
        $questionCoordinator = $services->questionCoordinator;
        $questionController = $services->questionController;

        $registry = $services->commandRegistry;

        $registry->bind('agents-live', new AgentsLiveCommandHandler($services->subagentLivePicker));

        $registry->bind('agents-main', new AgentsMainCommandHandler(
            $state,
            $screen,
            $questionCoordinator,
            $questionController,
            $services->childPoller,
            $client,
        ));
    }
}
