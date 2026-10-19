<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Doctrine\DBAL\Logging\Middleware;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Kernel;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/** Records actual DBAL statements without replacing the kernel's connection. */
final class SqlLoggingKernel extends Kernel
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->register('test.sql_logger', TestLogger::class)->setPublic(true);
        $container->register('test.sql_logging_middleware', Middleware::class)
            ->setArguments([new Reference('test.sql_logger')])
            ->addTag('doctrine.middleware');
    }
}
