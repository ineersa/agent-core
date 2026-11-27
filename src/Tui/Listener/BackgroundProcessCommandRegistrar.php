<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Psr\Log\LoggerInterface;

final readonly class BackgroundProcessCommandRegistrar implements TuiListenerRegistrar
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $context->sessionServices->commandRegistry->bind('bg', new BackgroundProcessCommandHandler($context, $this->logger));
    }
}
