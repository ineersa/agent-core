<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent;

use Ineersa\CodingAgent\Tool\BuiltInToolRegistrar;
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

        // app.cwd must reflect the actual working directory at runtime, not the
        // directory where the container was compiled. Use the HATFIELD_CWD env var
        // with a fallback to kernel.project_dir. The env var is set by:
        //   - AgentCommand (--cwd flag or getcwd() at startup)
        //   - JsonlProcessAgentSessionClient (passes --cwd=<path> to controller)
        //   - ConsumerSupervisor (Symfony Process cwd: argument sets child CWD)
        // Each process resolves its own CWD independently.
        $container->setParameter('app.cwd', '%env(default:kernel.project_dir:string:HATFIELD_CWD)%');

        // FrameworkBundle and MessengerPass handle all Messenger wiring
        // (buses, middleware, #[AsMessageHandler] attribute, handler-to-bus locators).
        // No custom compiler passes are needed.
    }

    public function boot(): void
    {
        // Always resolve HATFIELD_CWD from actual getcwd() — never inherit a
        // stale value from parent process env. Each process gets its CWD from
        // --cwd flag (chdir) or from OS at startup.
        $cwd = getcwd();
        if (false !== $cwd) {
            $_ENV['HATFIELD_CWD'] = $cwd;
            putenv('HATFIELD_CWD='.$cwd);
        }

        parent::boot();

        // Register all built-in tool providers as permanent tools.
        // The registrar collects all services tagged with hatfield.tool_provider
        // and calls ToolRegistryInterface::registerTool() for each.
        if ($this->container->has(BuiltInToolRegistrar::class)) {
            $this->container->get(BuiltInToolRegistrar::class)->registerTools();
        }
    }

    public function getConfigDir(): string
    {
        return $this->getProjectDir().'/config';
    }
}
