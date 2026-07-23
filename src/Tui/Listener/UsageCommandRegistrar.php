<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaProbeServiceInterface;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Registers the /usage slash command.
 *
 * Uses the idempotent registration pattern: if the command is already
 * registered, the handler is replaced rather than throwing. The handler is
 * bound to the active {@see TuiRuntimeContext::$state} so session totals
 * reflect the current TUI session.
 *
 * @internal Autowired via {@see TuiListenerRegistrar} and the `app.tui_listener` tag
 */
final class UsageCommandRegistrar implements TuiListenerRegistrar
{
    public function __construct(
        private readonly SlashCommandRegistry $commandRegistry,
        private readonly ProviderQuotaProbeServiceInterface $quotaProbe,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $handler = new UsageCommandHandler($this->quotaProbe, $context->state);

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
