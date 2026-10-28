<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Scheduling\SessionScheduleRepository;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Psr\Log\LoggerInterface;

final readonly class ScheduleCommandRegistrar implements TuiListenerRegistrar, SlashCommandCatalogRegistrar
{
    public function __construct(private SessionScheduleRepository $schedules, private LoggerInterface $logger)
    {
    }

    public function registerCatalog(SlashCommandCatalog $catalog): void
    {
        $catalog->registerMetadata(new CommandMetadata(name: 'schedule', description: 'List and cancel recurring work for this session', usage: '/schedule', acceptsArguments: false));
    }

    public function register(TuiRuntimeContext $context): void
    {
        $context->sessionServices->commandRegistry->bind('schedule', new ScheduleCommandHandler($context, $this->schedules, $this->logger));
    }
}
