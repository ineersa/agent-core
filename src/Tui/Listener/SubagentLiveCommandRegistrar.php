<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Registers /agents-live and /agents-main slash commands in the TUI.
 *
 * Command metadata is registered once per process via
 * {@see registerCatalog()}; each session binds fresh handlers wired to
 * the session's subagent live picker controller, question coordinator,
 * and question controller.
 */
final class SubagentLiveCommandRegistrar implements TuiListenerRegistrar, SlashCommandCatalogRegistrar
{
    public function registerCatalog(SlashCommandCatalog $catalog): void
    {
        $catalog->registerMetadata(new CommandMetadata(
            name: 'agents-live',
            description: 'Open interactive live view for a subagent',
            usage: '/agents-live',
            acceptsArguments: false,
        ));
        $catalog->registerMetadata(new CommandMetadata(
            name: 'agents-main',
            aliases: ['main'],
            description: 'Return from subagent live view to the main session',
            usage: '/agents-main',
            acceptsArguments: false,
        ));
    }

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
