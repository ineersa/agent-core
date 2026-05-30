<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    /**
     * @return iterable<\Symfony\Component\HttpKernel\Bundle\BundleInterface>
     */
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
        // app.cwd must reflect the actual working directory at runtime, not the
        // directory where the container was compiled. Use the HATFIELD_CWD env var
        // with a fallback to kernel.project_dir. The env var is set by:
        //   - AgentCommand (--cwd flag or getcwd() at startup)
        //   - JsonlProcessAgentSessionClient (passes --cwd=<path> to controller)
        //   - ConsumerSupervisor (Symfony Process cwd: argument sets child CWD)
        // Each process resolves its own CWD independently.
        $container->setParameter('app.cwd', '%env(default:kernel.project_dir:string:HATFIELD_CWD)%');
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
    }

    public function getConfigDir(): string
    {
        return $this->getProjectDir().'/config';
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/var/cache/'.$this->environment;
    }

    public function getBuildDir(): string
    {
        return $this->getCacheDir();
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir().'/var/log';
    }
}
