<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Theme;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Theme\ThemeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ThemeRegistry::class)]
final class ThemeRegistryTest extends TestCase
{
    private string $projectRoot;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = \dirname(__DIR__, 3);
        $this->tempDir = sys_get_temp_dir().'/theme-registry-test-'.getmypid();
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->tempDir)) {
            $this->rmDir($this->tempDir);
        }
    }

    public function testLoadBuiltinCyberpunkTheme(): void
    {
        $registry = $this->createRegistry();

        self::assertTrue($registry->has('cyberpunk'));

        $palette = $registry->getOrThrow('cyberpunk');
        self::assertSame('cyberpunk', $palette->name);
        self::assertSame('#00ffff', $palette->get(ThemeColorEnum::Accent));
        self::assertSame('#ff3366', $palette->get(ThemeColorEnum::Error));
        self::assertSame('#718096', $palette->get(ThemeColorEnum::Muted));
    }

    public function testLoadDirectoryFindsThemes(): void
    {
        $registry = $this->createRegistry();

        $names = $registry->getNames();
        self::assertContains('cyberpunk', $names);
        self::assertContains('nord', $names);
    }

    public function testGetOrThrowReturnsThemeWhenFound(): void
    {
        $registry = $this->createRegistry();
        $theme = $registry->getOrThrow('cyberpunk');

        self::assertSame('cyberpunk', $theme->name);
        self::assertSame('#00ffff', $theme->get(ThemeColorEnum::Accent));
    }

    public function testGetOrThrowThrowsWhenMissing(): void
    {
        $registry = $this->createEmptyRegistry();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Theme "nonexistent" is not registered');

        $registry->getOrThrow('nonexistent');
    }

    public function testGetOrThrowReturnsExactWhenFound(): void
    {
        $registry = $this->createRegistry();
        $theme = $registry->getOrThrow('nord');

        self::assertSame('nord', $theme->name);
        self::assertSame('#88c0d0', $theme->get(ThemeColorEnum::Accent));
    }

    public function testHas(): void
    {
        $registry = $this->createRegistry();

        self::assertTrue($registry->has('cyberpunk'));
        self::assertFalse($registry->has('nonexistent'));
    }

    public function testGetNames(): void
    {
        $registry = $this->createRegistry();
        $names = $registry->getNames();

        self::assertContains('cyberpunk', $names);
        self::assertContains('nord', $names);
        self::assertContains('tokyo-night', $names);
    }

    public function testRegisterAddsNewTheme(): void
    {
        $registry = $this->createEmptyRegistry();
        $registry->register(new ThemePalette('custom', ['accent' => '#abc']));

        self::assertTrue($registry->has('custom'));
        self::assertSame('#abc', $registry->get('custom')?->get(ThemeColorEnum::Accent));
    }

    public function testGetReturnsNullForMissing(): void
    {
        $registry = $this->createEmptyRegistry();

        self::assertNull($registry->get('nope'));
    }

    public function testDefaultName(): void
    {
        $registry = $this->createRegistry();

        self::assertTrue($registry->has('tokyo-night'));
        $theme = $registry->getOrThrow('tokyo-night');
        self::assertSame('tokyo-night', $theme->name);
    }

    private function createRegistry(): ThemeRegistry
    {
        $resources = new AppResourceLocator($this->projectRoot);
        $appConfig = new AppConfig(tui: new TuiConfig('cyberpunk', []));

        return new ThemeRegistry($appConfig, $resources);
    }

    private function createEmptyRegistry(): ThemeRegistry
    {
        // Point at an empty temp directory; registry will have no palettes.
        // getBuiltinThemesPath() returns $appRoot.'/config/themes', and since
        // the temp dir has no such subdirectory, loadDirectory() returns [].
        $resources = new AppResourceLocator($this->tempDir);
        $appConfig = new AppConfig(tui: new TuiConfig('cyberpunk', []));

        return new ThemeRegistry($appConfig, $resources);
    }

    private function rmDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir((string) $item);
            } else {
                unlink((string) $item);
            }
        }
        rmdir($dir);
    }
}
