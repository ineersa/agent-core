<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent;

use Ineersa\CodingAgent\Runtime\Process\PharExecutableLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    // Runtime-writable directory names under the Hatfield project cwd.
    // Never write to kernel.project_dir — it may point to a read-only
    // PHAR or shared source checkout. These dirs are created on demand
    // if they don't already exist.
    //
    // Installed PHAR/native compiled containers do NOT use this default:
    // they live under the global XDG/HOME cache with artifact identity
    // (see getCacheDir()). Project sessions/settings/logs stay here.
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
        // Installed PHAR and fused PHP-micro binaries compile Symfony's
        // container with %kernel.project_dir% = phar://<physical artifact>/...
        // That path is baked into generated services (e.g. AppResourceLocator
        // → ThemeRegistry). Cache identity must therefore include both the
        // artifact content hash and the canonical physical path so:
        //   - same bytes at different paths never share a container
        //   - different builds at the same path never share a container
        //   - symlink and target share a container (realpath)
        // Default root is global XDG/HOME, not project .hatfield/cache.
        if ($this->isPhar()) {
            return $this->resolveInstalledCacheDir();
        }

        // Source checkout: project-local (or explicit HATFIELD_CACHE_DIR root)
        // plus environment only — no artifact identity segment.
        return $this->resolveWritableDir('HATFIELD_CACHE_DIR', self::HATFIELD_CACHE_DIR).'/'.$this->environment;
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
     * Whether this process is an installed PHAR or fused native artifact.
     *
     * Box PHARs and PHP-micro fused natives both execute Kernel under a
     * phar:// stream, so the __FILE__ prefix is the runtime detector.
     * Do not use HATFIELD_BINARY_PATH — that is a subprocess override only.
     */
    private function isPhar(): bool
    {
        return str_starts_with(__FILE__, 'phar://');
    }

    /**
     * Installed-artifact cache:
     *   <root>/<environment>/<content-sha256>-<canonical-path-sha256>
     *
     * Full SHA-256 segments (no truncation) avoid cross-version collisions.
     */
    private function resolveInstalledCacheDir(): string
    {
        // Central Box alias / fused-native path resolution lives in
        // PharExecutableLocator — keep a single physical-path source of truth.
        $artifactPath = (new PharExecutableLocator())->path();
        $contentHash = hash_file('sha256', $artifactPath);
        if (false === $contentHash) {
            throw new \RuntimeException(\sprintf('Unable to hash installed artifact at "%s" for cache isolation. Check file permissions.', $artifactPath));
        }

        $canonicalPath = realpath($artifactPath);
        if (false === $canonicalPath) {
            throw new \RuntimeException(\sprintf('Unable to resolve canonical path for installed artifact at "%s". The file must exist and be readable.', $artifactPath));
        }

        $pathHash = hash('sha256', $canonicalPath);
        $identity = $contentHash.'-'.$pathHash;
        $dir = $this->resolveInstalledCacheRoot().'/'.$this->environment.'/'.$identity;
        $this->ensureDirectory($dir, 'installed artifact cache');

        return $dir;
    }

    /**
     * Root for installed-artifact compiled containers.
     *
     * Precedence:
     *   1. HATFIELD_CACHE_DIR (authoritative root; relative → runtime cwd)
     *   2. $XDG_CACHE_HOME/hatfield (absolute XDG only)
     *   3. $HOME/.cache/hatfield (absolute HOME only)
     *
     * Never falls back to the project working directory.
     */
    private function resolveInstalledCacheRoot(): string
    {
        $override = getenv('HATFIELD_CACHE_DIR');
        if (false !== $override && '' !== $override) {
            if (str_starts_with($override, '/')) {
                return rtrim($override, '/');
            }

            return rtrim($this->getRuntimeDir().'/'.$override, '/');
        }

        $xdg = getenv('XDG_CACHE_HOME');
        if (false !== $xdg && '' !== $xdg) {
            if (!str_starts_with($xdg, '/')) {
                throw new \RuntimeException(\sprintf('XDG_CACHE_HOME must be an absolute path for installed Hatfield cache resolution, got "%s". Set an absolute XDG_CACHE_HOME, absolute HOME, or HATFIELD_CACHE_DIR.', $xdg));
            }

            return rtrim($xdg, '/').'/hatfield';
        }

        $home = getenv('HOME');
        if (false !== $home && '' !== $home) {
            if (!str_starts_with($home, '/')) {
                throw new \RuntimeException(\sprintf('HOME must be an absolute path for installed Hatfield cache resolution, got "%s". Set an absolute HOME, absolute XDG_CACHE_HOME, or HATFIELD_CACHE_DIR.', $home));
            }

            return rtrim($home, '/').'/.cache/hatfield';
        }

        throw new \RuntimeException('Unable to determine installed Hatfield cache root: neither XDG_CACHE_HOME nor HOME is a non-empty absolute path. Set HATFIELD_CACHE_DIR, XDG_CACHE_HOME, or HOME.');
    }

    /**
     * Return the runtime cwd resolved from HATFIELD_CWD or getcwd().
     *
     * The runtime cwd is where .hatfield/ settings, sessions, logs,
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

    private function ensureDirectory(string $dir, string $label): void
    {
        if (is_dir($dir)) {
            if (!is_writable($dir)) {
                throw new \RuntimeException(\sprintf('Hatfield %s directory "%s" exists but is not writable.', $label, $dir));
            }

            return;
        }

        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Unable to create Hatfield %s directory "%s". Check permissions or set HATFIELD_CACHE_DIR to a writable absolute path.', $label, $dir));
        }
    }
}
