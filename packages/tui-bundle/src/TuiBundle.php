<?php

declare(strict_types=1);

namespace Ineersa\TuiBundle;

use Symfony\Component\Console\ConsoleBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\AbstractBundle;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

#[RequiredBundle(ConsoleBundle::class)]
final class TuiBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return $this->path ??= __DIR__;
    }

    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        // Load TUI integration services (to be defined in future iterations)
        // $configurator->import('../config/services.php');
    }
}
