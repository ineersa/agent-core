<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Cleanup must remove mode-restricted nested HOME trees used by TUI E2E isolation.
 */
final class TestDirectoryIsolationTest extends TestCase
{
    #[Test]
    public function removeDirectoryClearsModeRestrictedHomeCatalogTree(): void
    {
        $root = TestDirectoryIsolation::createOsTempDir('tdir-isolation-');
        $nested = $root.'/home/.hatfield';
        $this->assertTrue(@mkdir($nested, 0o700, true) || is_dir($nested));
        $catalog = $nested.'/ai-catalog.yaml';
        file_put_contents($catalog, "version: 1\nproviders: {}\n");
        $this->assertTrue(chmod($catalog, 0o600));
        $this->assertTrue(chmod($nested, 0o700));
        $this->assertTrue(chmod($root.'/home', 0o700));

        TestDirectoryIsolation::removeDirectory($root);

        $this->assertDirectoryDoesNotExist($root);
    }

    #[Test]
    public function removalDoesNotFollowOrChmodSymlinkTargets(): void
    {
        $root = TestDirectoryIsolation::createOsTempDir('test-directory-links');
        $outside = TestDirectoryIsolation::createOsTempDir('test-directory-outside');
        file_put_contents($outside.'/keep', 'unrelated');
        chmod($outside, 0700);
        symlink($outside, $root.'/external');
        symlink($outside, $root.'/alias');
        try {
            TestDirectoryIsolation::removeDirectory($root.'/alias');
            $this->assertFileExists($outside.'/keep');
            TestDirectoryIsolation::removeDirectory($root);
            $this->assertFileExists($outside.'/keep');
            $this->assertSame(0700, fileperms($outside) & 0777);
        } finally {
            TestDirectoryIsolation::removeDirectory($root);
            TestDirectoryIsolation::removeDirectory($outside);
        }
    }

    #[Test]
    public function removalNormalizesDirectoriesBeforeDescending(): void
    {
        $root = TestDirectoryIsolation::createOsTempDir('test-directory-restricted');
        mkdir($root.'/nested/hidden', 0700, true);
        file_put_contents($root.'/nested/hidden/proof', 'fixture');
        chmod($root.'/nested', 0000);
        TestDirectoryIsolation::removeDirectory($root);
        $this->assertDirectoryDoesNotExist($root);
    }
}
