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
        // with a fallback to kernel.project_dir.
        //
        // HATFIELD_CWD is set during bootstrap:
        //   1. bin/console early --cwd handling resolves and chdirs before Kernel
        //      construction, then sets HATFIELD_CWD and mutates argv.
        //   2. Kernel::boot() sets it defensively from getcwd() (already correct
        //      after step 1).
        //   3. JsonlProcessAgentSessionClient passes --cwd=<runtimeCwd> to the
        //      spawned controller process, which repeats step 1.
        //   4. ConsumerSupervisor sets Symfony Process cwd: argument, which
        //      sets the child process CWD independently.
        // Each process resolves its own CWD at its bootstrap boundary.
        $container->setParameter('app.cwd', '%env(default:kernel.project_dir:string:HATFIELD_CWD)%');
    }

    public function boot(): void
    {
        // Bootstrap fallback: resolve HATFIELD_CWD from actual getcwd().
        // When bin/console is the entry point, the process CWD was already
        // changed by the early --cwd handling above. When the kernel is
        // booted directly (e.g. tests), this ensures HATFIELD_CWD is set
        // to the actual process CWD even without the bin/console bootstrap.
        // Service-level code must use %app.cwd% (from this env/parameter),
        // not ambient getcwd().
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
