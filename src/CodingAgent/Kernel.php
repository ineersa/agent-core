<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\AbstractKernel;
use Symfony\Component\DependencyInjection\Kernel\KernelTrait;

class Kernel extends AbstractKernel
{
    use KernelTrait;

    public function registerBundles(): iterable
    {
        $bundles = require $this->getConfigDir().'/bundles.php';

        foreach ($bundles as $class => $envs) {
            if ($envs[$this->environment] ?? $envs['all'] ?? false) {
                yield new $class();
            }
        }
    }

    public function build(ContainerBuilder $container): void
    {
        // FrameworkBundle requires kernel.charset for web/service infrastructure.
        // HttpKernel\Kernel normally sets this; we must set it manually since our
        // Kernel extends AbstractKernel (not HttpKernel\Kernel).
        $container->setParameter('kernel.charset', 'UTF-8');
        $container->setParameter('kernel.default_locale', 'en');

        // FrameworkBundle and MessengerPass handle all Messenger wiring
        // (buses, middleware, #[AsMessageHandler] attribute, handler-to-bus locators).
        // No custom compiler passes are needed.
    }

    public function getConfigDir(): string
    {
        return $this->getProjectDir().'/config';
    }
}
