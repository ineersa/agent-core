<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Registers the /agent-poc slash command for the AGENT-03 POC.
 *
 * THIS IS THROWAWAY POC CODE — registered at low priority so it
 * does not interfere with production commands.  The handler and
 * overlay should be deleted before production implementation.
 *
 * @internal
 */
final class AgentPocRegistrar implements TuiListenerRegistrar
{
    public function __construct(
        private readonly SlashCommandRegistry $commandRegistry,
    ) {
    }

    public static function getPriority(): int
    {
        return -50;
    }

    public function register(TuiRuntimeContext $context): void
    {
        $handler = new AgentPocCommandHandler(
            $context->sessionStore,
            $context->state,
            $context->screen,
        );

        if ($this->commandRegistry->has('agent-poc')) {
            $this->commandRegistry->setHandler('agent-poc', $handler);

            return;
        }

        $this->commandRegistry->register(
            new CommandMetadata(
                name: 'agent-poc',
                aliases: ['ap'],
                description: '[POC] Agent control overlay — throwaway prototype',
                usage: '/agent-poc [tick|close]',
                acceptsArguments: true,
            ),
            $handler,
        );
    }
}
