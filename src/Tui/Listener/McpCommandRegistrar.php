<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\BuiltInSlashCommands;
use Ineersa\Tui\CompactHeader\McpStatusSnapshotProvider;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Psr\Log\LoggerInterface;

/**
 * Registers the /mcp slash command.
 *
 * Command metadata is static data in {@see BuiltInSlashCommands}; each
 * session binds a fresh handler.
 *
 * @internal Autowired via {@see TuiListenerRegistrar} and the `app.tui_listener` tag
 */
final class McpCommandRegistrar implements TuiListenerRegistrar
{
    public function __construct(
        private readonly McpStatusSnapshotProvider $statusProvider,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $handler = new McpCommandHandler(
            $this->statusProvider,
            $context->client,
            $context->state,
            $context->screen,
            $context->tui,
            $this->logger,
        );

        $context->sessionServices->commandRegistry->bind('mcp', $handler);
    }
}
