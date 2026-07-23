<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * Self-contained OM package kernel.
 *
 * Independent of Hatfield's Kernel, Doctrine connections, and Messenger buses.
 * Database path and parent PID are supplied by the extension supervisor via env.
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return \dirname(__DIR__);
    }

    public function getCacheDir(): string
    {
        $override = $_ENV['OM_CACHE_DIR'] ?? $_SERVER['OM_CACHE_DIR'] ?? null;
        if (\is_string($override) && '' !== $override) {
            return rtrim($override, '/').'/'.$this->environment;
        }

        return $this->getProjectDir().'/var/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        $override = $_ENV['OM_LOG_DIR'] ?? $_SERVER['OM_LOG_DIR'] ?? null;
        if (\is_string($override) && '' !== $override) {
            return rtrim($override, '/');
        }

        return $this->getProjectDir().'/var/log';
    }
}
