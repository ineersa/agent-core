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

        // In source checkout (test env), the cache dir should end with /test
        // without content/path hash identity segments.
        $this->assertMatchesRegularExpression(
            '#/test$#',
            $cacheDir,
            'Source-checkout cache dir should end with /test (no artifact identity). Got: '.$cacheDir
        );
        $this->assertStringNotContainsString(
            '/hatfield/',
            $cacheDir,
            'Source-checkout must not use the global installed-artifact cache root.',
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
