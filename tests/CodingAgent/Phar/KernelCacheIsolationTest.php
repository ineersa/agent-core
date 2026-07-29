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

        // Source mode: project-local .hatfield/cache/<env> under the isolated
        // runtime CWD — no XDG/HOME installed root and no artifact identity.
        $expected = $this->isolatedCwd().'/.hatfield/cache/test';
        $this->assertSame(
            $expected,
            $cacheDir,
            'Source-checkout cache must stay project-local under .hatfield/cache/test (no installed XDG identity). Got: '.$cacheDir
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
