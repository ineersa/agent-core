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
}
