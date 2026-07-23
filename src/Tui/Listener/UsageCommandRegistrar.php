<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaProbeServiceInterface;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Psr\Log\LoggerInterface;

/**
 * Registers the /usage slash command.
 *
 * @internal Autowired via {@see TuiListenerRegistrar} and the `app.tui_listener` tag
 */
final class UsageCommandRegistrar implements TuiListenerRegistrar
{
    public function __construct(
        private readonly SlashCommandRegistry $commandRegistry,
        private readonly ProviderQuotaProbeServiceInterface $quotaProbe,
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

        if ($this->commandRegistry->has('usage')) {
            $this->commandRegistry->setHandler('usage', $handler);

            return;
        }

        $this->commandRegistry->register(
            new CommandMetadata(
                name: 'usage',
                description: 'Show OpenAI Codex and z.ai quota status plus session usage',
                usage: '/usage',
                acceptsArguments: false,
            ),
            $handler,
        );
    }
}
