<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Infrastructure\ProviderQuota\ProviderQuotaProbeService;
use Ineersa\Tui\Command\BuiltInSlashCommands;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Psr\Log\LoggerInterface;

/**
 * Registers the /usage slash command.
 *
 * Command metadata is static data in {@see BuiltInSlashCommands}; each
 * session binds a fresh handler.
 *
 * @internal Autowired via {@see TuiListenerRegistrar} and the `app.tui_listener` tag
 */
final class UsageCommandRegistrar implements TuiListenerRegistrar
{
    public function __construct(
        private readonly ProviderQuotaProbeService $quotaProbe,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $handler = new UsageCommandHandler(
            $this->quotaProbe,
            $context->state,
            $context->screen,
            $context->tui,
            $this->logger,
        );

        $context->sessionServices->commandRegistry->bind('usage', $handler);
    }
}
