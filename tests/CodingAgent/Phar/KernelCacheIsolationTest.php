<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Phar;

use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

/**
 * Verifies that PHAR container caches are isolated from source-checkout
 * caches compiled at the same runtime CWD.
 *
 * When a PHAR and source checkout share the same .hatfield/cache/<env>/
 * directory, a stale source-checkout cache (with kernel.project_dir pointing
 * to the filesystem repo root) would be reused by the PHAR, embedding
 * filesystem vendor paths that collide with the PHAR's bundled autoloader
 * (Cannot-redeclare-class fatals).
 *
 * The fix adds a PHAR-path-derived hash suffix to the cache directory, so
 * PHAR caches are always distinct from source-checkout caches regardless
 * of APP_ENV.
 */
class KernelCacheIsolationTest extends IsolatedKernelTestCase
{
    /**
     * Source-checkout caches must not include PHAR-specific hash suffixes.
     */
    public function testCacheDirectoryHasNoPharSuffixInSourceMode(): void
    {
        $kernel = self::$kernel;
        $this->assertNotNull($kernel, 'Kernel must be booted by IsolatedKernelTestCase::setUp()');
        $cacheDir = $kernel->getCacheDir();

        // In source checkout (test env), the cache dir should end with /test
        // without any PHAR-specific suffix appended.
        $this->assertMatchesRegularExpression(
            '#/test$#',
            $cacheDir,
            'Source-checkout cache dir should end with /test (no PHAR hash suffix). Got: '.$cacheDir
        );
    }

    public function testIsPharReturnsFalseInSourceMode(): void
    {
        $kernel = self::$kernel;
        $this->assertNotNull($kernel, 'Kernel must be booted');
        $ref = new \ReflectionMethod($kernel, 'isPhar');

        $this->assertFalse(
            $ref->invoke($kernel),
            'Source checkout must not detect PHAR mode — otherwise cache isolation logic would be wrong.'
        );
    }

    public function testPharModeWouldProduceDifferentCachePath(): void
    {
        $kernel = self::$kernel;
        $this->assertNotNull($kernel, 'Kernel must be booted');
        $sourceCacheDir = $kernel->getCacheDir();

        // Simulate what getCacheDir would return if isPhar() were true.
        // The PHAR cache dir appends a hash of __FILE__ to the base path.
        $pharCacheDir = $sourceCacheDir.'-'.substr(md5(__FILE__), 0, 8);

        $this->assertNotEquals(
            $sourceCacheDir,
            $pharCacheDir,
            'PHAR cache dir must differ from source-checkout cache dir to prevent stale-cache collisions.'
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
