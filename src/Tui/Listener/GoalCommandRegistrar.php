<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Session\Goal\SessionGoalService;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

final readonly class GoalCommandRegistrar implements TuiListenerRegistrar, SlashCommandCatalogRegistrar
{
    public function __construct(private SessionGoalService $goals)
    {
    }

    public function registerCatalog(SlashCommandCatalog $catalog): void
    {
        $catalog->registerMetadata(new CommandMetadata(
            name: 'goal',
            description: 'Show, set, edit or clear the session goal',
            usage: '/goal [objective | edit | clear | cancel]',
            acceptsArguments: true,
        ));
    }

    public function register(TuiRuntimeContext $context): void
    {
        $context->sessionServices->commandRegistry->bind('goal', new GoalCommandHandler($this->goals, $context->state, $context->screen));
    }
}
