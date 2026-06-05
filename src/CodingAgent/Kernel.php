<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    // Runtime-writable directory names under the Hatfield project cwd.
    // Never write to kernel.project_dir — it may point to a read-only
    // PHAR or shared source checkout. These dirs are created on demand
    // if they don't already exist.
    private const string HATFIELD_CACHE_DIR = '.hatfield/cache';
    private const string HATFIELD_LOG_DIR = '.hatfield/logs';

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
        $base = $this->resolveWritableDir('HATFIELD_CACHE_DIR', self::HATFIELD_CACHE_DIR).'/'.$this->environment;

        // When running inside a PHAR, isolate the cache from source-checkout
        // caches compiled at the same runtime CWD. A source-checkout project_dir
        // and PHAR project_dir differ (filesystem vs phar:// URI), and stale
        // source-checkout caches embed filesystem paths that collide with the
        // PHAR's bundled vendor autoloader, causing Cannot-redeclare-class
        // fatals in subprocess controllers.
        //
        // The suffix is a content hash of the physical PHAR archive file,
        // NOT a hash of __FILE__ (which is a stable phar:// URI that never
        // changes across builds). A stable suffix reuses stale compiled
        // Symfony containers whose service definitions encode constructor
        // signatures, paths, and class shapes from the previous PHAR build —
        // breaking the container when any service changes its constructor
        // parameters.
        if ($this->isPhar()) {
            $pharPath = $this->resolvePharPath();
            $pharHash = hash_file('sha256', $pharPath);
            if (false === $pharHash) {
                throw new \RuntimeException(\sprintf('Unable to hash PHAR archive at "%s" for cache isolation. Check file permissions.', $pharPath));
            }

            $base .= '-'.substr($pharHash, 0, 12);
        }

        return $base;
    }

    public function getBuildDir(): string
    {
        return $this->getCacheDir();
    }

    public function getLogDir(): string
    {
        return $this->resolveWritableDir('HATFIELD_LOG_DIR', self::HATFIELD_LOG_DIR);
    }

    /**
     * Whether the current process is running inside a PHAR archive.
     *
     * Detects Box-compiled PHARs where __FILE__ starts with phar://.
     * Used for cache isolation and writable-dir resolution.
     */
    private function isPhar(): bool
    {
        return str_starts_with(__FILE__, 'phar://');
    }

    /**
     * Resolve the physical filesystem path of the running PHAR archive.
     *
     * Resolution order (matches PharExecutableLocator):
     *   1. Phar::running(false) — standard PHAR detection.
     *   2. When Box 4.x uses an auto-generated internal alias
     *      (Phar::running() returns empty), construct a Phar object
     *      from __FILE__ and call getPath() to recover the physical path.
     *
     * Only called after isPhar() has confirmed __FILE__ starts with
     * phar://.
     *
     * @throws \RuntimeException when the physical PHAR path cannot be
     *                           resolved or does not exist on disk
     */
    private function resolvePharPath(): string
    {
        // 1. Standard PHAR detection — works for most PHAR archives
        //    including manually created ones with a filesystem alias.
        $pharPath = \Phar::running(false);
        if ('' !== $pharPath && is_file($pharPath)) {
            return $pharPath;
        }

        // 2. Box 4.x+ fallback: the auto-generated internal alias
        //    makes Phar::running() return empty, but the phar:// prefix
        //    in __FILE__ lets us construct a Phar object whose getPath()
        //    resolves back to the physical archive on disk.
        if (str_starts_with(__FILE__, 'phar://')) {
            $previous = null;
            try {
                $phar = new \Phar(__FILE__);
                $physicalPath = $phar->getPath();
                if ('' !== $physicalPath && is_file($physicalPath)) {
                    return $physicalPath;
                }
            } catch (\Throwable $e) {
                $previous = $e;
            }

            throw new \RuntimeException(\sprintf('Running inside a PHAR (%s) but unable to resolve the physical archive path. Phar::running(false) returned "%s". Constructing a Phar object from __FILE__ failed%s.', __FILE__, $pharPath, null !== $previous ? ': '.$previous->getMessage() : ' (resolved path is empty or not a file)'), 0, $previous);
        }

        // This branch should be unreachable because isPhar() guards the
        // call site in getCacheDir().  Fail loudly if reached anyway.
        throw new \RuntimeException(\sprintf('resolvePharPath() called but __FILE__ "%s" does not contain a phar:// prefix and Phar::running(false) returned "%s".', __FILE__, $pharPath));
    }

    /**
     * Return the runtime cwd resolved from HATFIELD_CWD or getcwd().
     *
     * The runtime cwd is where .hatfield/ settings, sessions, logs, cache,
     * and the messenger DB live. It is NOT the app install root
     * (kernel.project_dir), which may be a read-only PHAR path.
     */
    private function getRuntimeDir(): string
    {
        $cwd = getenv('HATFIELD_CWD');
        if (false !== $cwd && '' !== $cwd) {
            return $cwd;
        }

        $cwd = getcwd();
        if (false !== $cwd) {
            return $cwd;
        }

        throw new \RuntimeException('Unable to determine runtime working directory. Set HATFIELD_CWD or ensure getcwd() returns a valid path.');
    }

    /**
     * Resolve a writable directory under the runtime cwd.
     *
     * Checks HATFIELD_{NAME}_DIR env override first. If the override is a
     * relative path it is resolved against the runtime cwd. Falls back to
     * a default path under .hatfield/.
     */
    private function resolveWritableDir(string $envName, string $default): string
    {
        $override = getenv($envName);
        if (false !== $override && '' !== $override) {
            if (str_starts_with($override, '/')) {
                return $override;
            }

            return $this->getRuntimeDir().'/'.$override;
        }

        return $this->getRuntimeDir().'/'.$default;
    }
}
