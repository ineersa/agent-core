<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\BuiltInSlashCommands;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Registers the /compact slash command.
 *
 * Command metadata is static data in {@see BuiltInSlashCommands}; each
 * session binds a fresh handler.
 *
 * @internal Autowired via {@see TuiListenerRegistrar} and the `app.tui_listener` tag
 */
final class CompactCommandRegistrar implements TuiListenerRegistrar
{
    public function register(TuiRuntimeContext $context): void
    {
        $handler = new CompactCommandHandler($context->client, $context->state);

        $context->sessionServices->commandRegistry->bind('compact', $handler);
    }
}
