<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\BuiltInSlashCommands;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Registers the /history slash command.
 *
 * Command metadata is static data in {@see BuiltInSlashCommands}; each
 * session binds a fresh handler wired to the session's history picker controller.
 */
final class HistoryCommandRegistrar implements TuiListenerRegistrar
{
    public function register(TuiRuntimeContext $context): void
    {
        $picker = $context->sessionServices->historyPicker;
        $handler = new HistoryCommandHandler($picker);

        $context->sessionServices->commandRegistry->bind('history', $handler);
    }
}
