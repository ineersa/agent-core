<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Phar;

use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

/**
 * Source-checkout cache contract.
 *
 * Source checkouts keep project-local (or HATFIELD_CACHE_DIR override)
 * Symfony containers under `<root>/<environment>` with no artifact
 * identity segment. Installed PHAR/native identity isolation is proven
 * by PharSmokeTest and PharArtifactCacheRelocationTest against real
 * archives — this class only covers the source path on a booted kernel.
 */
class KernelCacheIsolationTest extends IsolatedKernelTestCase
{
    /**
     * Source-checkout caches must not include PHAR/native artifact identity.
     */
    public function testCacheDirectoryHasNoArtifactIdentityInSourceMode(): void
    {
        $kernel = self::$kernel;
        $this->assertNotNull($kernel, 'Kernel must be booted by IsolatedKernelTestCase::setUp()');
        $cacheDir = $kernel->getCacheDir();

        // Source mode honors HATFIELD_CACHE_DIR override (ParaTest sets a
        // relative per-worker root) then appends /<env> only — no XDG
        // installed root and no artifact identity segment.
        $override = getenv('HATFIELD_CACHE_DIR');
        if (false !== $override && '' !== $override) {
            $root = str_starts_with($override, '/')
                ? $override
                : $this->isolatedCwd().'/'.$override;
        } else {
            $root = $this->isolatedCwd().'/.hatfield/cache';
        }
        $expected = $root.'/test';
        $this->assertSame(
            $expected,
            $cacheDir,
            'Source-checkout cache must be override root + /test with no installed XDG identity. Got: '.$cacheDir
        );
    }

    public function testCacheDirectoryIsDeterministic(): void
    {
        $kernel = self::$kernel;
        $this->assertNotNull($kernel, 'Kernel must be booted');

        $dir1 = $kernel->getCacheDir();
        $dir2 = $kernel->getCacheDir();

        $this->assertSame($dir1, $dir2, 'Cache directory must be deterministic across repeated calls.');
    }
}
