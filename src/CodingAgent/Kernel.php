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

        // app.cwd must be resolved at RUNTIME, not compile time. Container
        // compilation may happen in a different working directory (tests,
        // parallel controllers). Baking getcwd() into the cached container
        // would cause a stale CWD to leak across compilations.
        $container->setParameter('app.cwd', '%env(default:kernel.project_dir:string:HATFIELD_CWD)%');

        // FrameworkBundle and MessengerPass handle all Messenger wiring
        // (buses, middleware, #[AsMessageHandler] attribute, handler-to-bus locators).
        // No custom compiler passes are needed.
    }

    public function boot(): void
    {
        parent::boot();

        // Resolve app.cwd at boot time so it reflects the actual working
        // directory. HATFIELD_CWD is set by the parent process (JsonlProcess-
        // AgentSessionClient) or defaults to the CWD of the calling process.
        // The env var is set via proc_open($env) for the controller child
        // or via putenv() in AgentCommand for the main TUI process.
        $cwd = getcwd();
        if (false !== $cwd) {
            $_ENV['HATFIELD_CWD'] = $cwd;
        }
    }

    public function getConfigDir(): string
    {
        return $this->getProjectDir().'/config';
    }
}
