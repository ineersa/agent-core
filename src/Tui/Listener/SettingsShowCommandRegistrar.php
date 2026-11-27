<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\BuiltInSlashCommands;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Registers the /settings-show slash command.
 *
 * Command metadata is static data in {@see BuiltInSlashCommands}; the
 * handler is process-owned and stateless.
 *
 * @internal Autowired via {@see TuiListenerRegistrar} and the `app.tui_listener` tag
 */
final class SettingsShowCommandRegistrar implements TuiListenerRegistrar
{
    public function __construct(
        private readonly SettingsShowCommandHandler $handler,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $context->sessionServices->commandRegistry->bind('settings-show', $this->handler);
    }
}
