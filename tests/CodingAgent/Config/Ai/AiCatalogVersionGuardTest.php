<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config\Ai;

use Ineersa\CodingAgent\Config\Ai\AiCatalogVersionGuard;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

final class AiCatalogVersionGuardTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('catalog_version_guard');
        TestDirectoryIsolation::ensureDirectory($this->tmpDir.'/config');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testSoftPassWhenBaseRefUnavailable(): void
    {
        $guard = new AiCatalogVersionGuard($this->tmpDir, static function (string $args): array {
            if (str_starts_with($args, 'rev-parse --verify')) {
                return ['exit' => 128, 'out' => '', 'err' => 'missing'];
            }

            self::fail('unexpected git call: '.$args);
        });

        $result = $guard->check('origin/main');
        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['errors']);
        $this->assertNotEmpty($result['notes']);
        $this->assertStringContainsString('unavailable; soft-pass', $result['notes'][0]);
    }

    public function testPassWhenFileUnchanged(): void
    {
        file_put_contents($this->tmpDir.'/config/ai-catalog.yaml', "version: 1\nproviders: {}\n");

        $guard = new AiCatalogVersionGuard($this->tmpDir, static function (string $args): array {
            if (str_starts_with($args, 'rev-parse --verify')) {
                return ['exit' => 0, 'out' => "origin/main\n", 'err' => ''];
            }
            if (str_starts_with($args, 'merge-base')) {
                return ['exit' => 0, 'out' => "abc123\n", 'err' => ''];
            }
            if (str_starts_with($args, 'cat-file -e')) {
                return ['exit' => 0, 'out' => '', 'err' => ''];
            }
            if (str_starts_with($args, 'diff --quiet')) {
                return ['exit' => 0, 'out' => '', 'err' => ''];
            }

            self::fail('unexpected git call: '.$args);
        });

        $result = $guard->check();
        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['errors']);
    }

    public function testPassWhenFileNewRelativeToBase(): void
    {
        $guard = new AiCatalogVersionGuard($this->tmpDir, static function (string $args): array {
            if (str_starts_with($args, 'rev-parse --verify')) {
                return ['exit' => 0, 'out' => "origin/main\n", 'err' => ''];
            }
            if (str_starts_with($args, 'merge-base')) {
                return ['exit' => 0, 'out' => "abc123\n", 'err' => ''];
            }
            if (str_starts_with($args, 'cat-file -e')) {
                return ['exit' => 128, 'out' => '', 'err' => 'missing'];
            }

            self::fail('unexpected git call: '.$args);
        });

        $result = $guard->check();
        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('is new relative to', $result['notes'][0]);
    }

    public function testFailWhenChangedWithoutVersionBump(): void
    {
        file_put_contents($this->tmpDir.'/config/ai-catalog.yaml', "version: 1\nproviders:\n    zai: {}\n");

        $guard = new AiCatalogVersionGuard($this->tmpDir, static function (string $args): array {
            if (str_starts_with($args, 'rev-parse --verify')) {
                return ['exit' => 0, 'out' => "origin/main\n", 'err' => ''];
            }
            if (str_starts_with($args, 'merge-base')) {
                return ['exit' => 0, 'out' => "abc123\n", 'err' => ''];
            }
            if (str_starts_with($args, 'cat-file -e')) {
                return ['exit' => 0, 'out' => '', 'err' => ''];
            }
            if (str_starts_with($args, 'diff --quiet')) {
                return ['exit' => 1, 'out' => '', 'err' => ''];
            }
            if (str_starts_with($args, 'show ')) {
                return ['exit' => 0, 'out' => "version: 1\nproviders: {}\n", 'err' => ''];
            }

            self::fail('unexpected git call: '.$args);
        });

        $result = $guard->check();
        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('without a version bump', $result['errors'][0]);
        $this->assertStringContainsString('old=1, new=1', $result['errors'][0]);
    }

    public function testPassWhenChangedWithVersionBump(): void
    {
        file_put_contents($this->tmpDir.'/config/ai-catalog.yaml', "version: 2\nproviders:\n    zai: {}\n");

        $guard = new AiCatalogVersionGuard($this->tmpDir, static function (string $args): array {
            if (str_starts_with($args, 'rev-parse --verify')) {
                return ['exit' => 0, 'out' => "origin/main\n", 'err' => ''];
            }
            if (str_starts_with($args, 'merge-base')) {
                return ['exit' => 0, 'out' => "abc123\n", 'err' => ''];
            }
            if (str_starts_with($args, 'cat-file -e')) {
                return ['exit' => 0, 'out' => '', 'err' => ''];
            }
            if (str_starts_with($args, 'diff --quiet')) {
                return ['exit' => 1, 'out' => '', 'err' => ''];
            }
            if (str_starts_with($args, 'show ')) {
                return ['exit' => 0, 'out' => "version: 1\nproviders: {}\n", 'err' => ''];
            }

            self::fail('unexpected git call: '.$args);
        });

        $result = $guard->check();
        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['errors']);
    }
}
