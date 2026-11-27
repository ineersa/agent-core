<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Session\Goal\SessionGoalService;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

final readonly class GoalCommandRegistrar implements TuiListenerRegistrar
{
    public function __construct(private SessionGoalService $goals)
    {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $context->sessionServices->commandRegistry->bind('goal', new GoalCommandHandler($this->goals, $context->state, $context->screen));
    }
}
