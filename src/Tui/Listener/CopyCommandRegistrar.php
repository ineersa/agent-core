<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\BuiltInSlashCommands;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Utility\Clipboard;

/**
 * Registers the /copy (alias: /cp) slash command.
 *
 * Command metadata is static data in {@see BuiltInSlashCommands}; each
 * session binds a fresh handler.
 *
 * @internal Autowired via {@see TuiListenerRegistrar} and the `app.tui_listener` tag
 */
final class CopyCommandRegistrar implements TuiListenerRegistrar
{
    public function __construct(private readonly Clipboard $clipboard)
    {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $handler = new CopyCommandHandler($context->state, $this->clipboard);

        $context->sessionServices->commandRegistry->bind('copy', $handler);
    }
}
