<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Infrastructure\ProviderQuota\ProviderQuotaProbeService;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Psr\Log\LoggerInterface;

/**
 * Registers the /usage slash command.
 *
 * Command metadata is registered once per process via
 * {@see registerCatalog()}; each session binds a fresh handler.
 *
 * @internal Autowired via {@see TuiListenerRegistrar} and the `app.tui_listener` tag
 */
final class UsageCommandRegistrar implements TuiListenerRegistrar, SlashCommandCatalogRegistrar
{
    public function __construct(
        private readonly ProviderQuotaProbeService $quotaProbe,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function registerCatalog(SlashCommandCatalog $catalog): void
    {
        $catalog->registerMetadata(new CommandMetadata(
            name: 'usage',
            description: 'Show OpenAI Codex and z.ai quota status plus session usage',
            usage: '/usage',
            acceptsArguments: false,
        ));
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
