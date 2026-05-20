<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent;

use Ineersa\CodingAgent\Integration\MessengerIntegrationCompilerPass;
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
        // Register #[AsMessageHandler] → messenger.message_handler tag mapping
        // BEFORE any compiler passes run, so the autoconfigure scanner discovers
        // handler services and MessengerPass can wire them into buses.
        MessengerIntegrationCompilerPass::registerHandlerAttribute($container);

        // Wire bus infrastructure (tagging, middleware, HandlersLocator) and
        // delegate to Symfony's own MessengerPass for handler-to-bus wiring.
        $container->addCompilerPass(new MessengerIntegrationCompilerPass());
    }

    public function getConfigDir(): string
    {
        return $this->getProjectDir().'/config';
    }
}
