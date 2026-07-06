<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Theme;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Theme\ThemeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(ThemeRegistry::class)]
final class ThemeRegistryTest extends TestCase
{
    private string $projectRoot;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = \Ineersa\CodingAgent\Tests\Support\ProjectDir::get();
        $this->tempDir = TestDirectoryIsolation::createProjectTempDir('theme-registry-test');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TestDirectoryIsolation::removeDirectory($this->tempDir);
    }

    public function testLoadBuiltinCyberpunkTheme(): void
    {
        $registry = $this->createRegistry();

        $this->assertTrue($registry->has('cyberpunk'));

        $palette = $registry->getOrThrow('cyberpunk');
        $this->assertSame('cyberpunk', $palette->name);
        $this->assertSame('#00ffff', $palette->get(ThemeColorEnum::Accent));
        $this->assertSame('#ff3366', $palette->get(ThemeColorEnum::Error));
        $this->assertSame('#718096', $palette->get(ThemeColorEnum::Muted));
    }

    public function testLoadDirectoryFindsThemes(): void
    {
        $registry = $this->createRegistry();

        $names = $registry->getNames();
        $this->assertContains('cyberpunk', $names);
        $this->assertContains('nord', $names);
    }

    public function testGetOrThrowReturnsThemeWhenFound(): void
    {
        $registry = $this->createRegistry();
        $theme = $registry->getOrThrow('cyberpunk');

        $this->assertSame('cyberpunk', $theme->name);
        $this->assertSame('#00ffff', $theme->get(ThemeColorEnum::Accent));
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

        $this->assertSame('nord', $theme->name);
        $this->assertSame('#88c0d0', $theme->get(ThemeColorEnum::Accent));
    }

    public function testHas(): void
    {
        $registry = $this->createRegistry();

        $this->assertTrue($registry->has('cyberpunk'));
        $this->assertFalse($registry->has('nonexistent'));
    }

    public function testGetNames(): void
    {
        $registry = $this->createRegistry();
        $names = $registry->getNames();

        $this->assertContains('cyberpunk', $names);
        $this->assertContains('nord', $names);
        $this->assertContains('tokyo-night', $names);
    }

    public function testRegisterAddsNewTheme(): void
    {
        $registry = $this->createEmptyRegistry();
        $registry->register(new ThemePalette('custom', ['accent' => '#abc']));

        $this->assertTrue($registry->has('custom'));
        $this->assertSame('#abc', $registry->get('custom')?->get(ThemeColorEnum::Accent));
    }

    public function testGetReturnsNullForMissing(): void
    {
        $registry = $this->createEmptyRegistry();

        $this->assertNull($registry->get('nope'));
    }

    public function testDefaultName(): void
    {
        $registry = $this->createRegistry();

        $this->assertTrue($registry->has('tokyo-night'));
        $theme = $registry->getOrThrow('tokyo-night');
        $this->assertSame('tokyo-night', $theme->name);
    }

    public function testBuiltinThemePathsOverlapProducesNoSelfCollisions(): void
    {
        $resources = new AppResourceLocator($this->projectRoot);
        $builtin = $resources->getBuiltinThemesPath();
        $appConfig = new AppConfig(tui: new TuiConfig('cyberpunk', [$builtin]), logging: new LoggingConfig());
        $registry = new ThemeRegistry($appConfig, $resources, new NullLogger());

        $this->assertSame([], $registry->getThemeCollisions());
        $this->assertTrue($registry->has('nord'));
    }

    public function testSameSourcePathDuplicateRegistrationProducesNoCollision(): void
    {
        $registry = $this->createEmptyRegistry();
        $themeFile = $this->tempDir.'/dup-theme.yaml';
        file_put_contents($themeFile, "name: dup-test\naccent: '#111111'\n");

        $palette = ThemePalette::fromArray(['name' => 'dup-test', 'accent' => '#111111']);
        $registry->register($palette, $themeFile);
        $registry->register($palette, $themeFile);

        $this->assertSame([], $registry->getThemeCollisions());
        $this->assertTrue($registry->has('dup-test'));
    }

    public function testDifferentSourcePathDuplicateNameRecordsCollision(): void
    {
        $registry = $this->createEmptyRegistry();
        $winnerFile = $this->tempDir.'/winner.yaml';
        $loserFile = $this->tempDir.'/loser.yaml';
        file_put_contents($winnerFile, "name: clash\naccent: '#111111'\n");
        file_put_contents($loserFile, "name: clash\naccent: '#222222'\n");

        $registry->register(ThemePalette::fromArray(['name' => 'clash', 'accent' => '#111111']), $winnerFile);
        $registry->register(ThemePalette::fromArray(['name' => 'clash', 'accent' => '#222222']), $loserFile);

        $collisions = $registry->getThemeCollisions();
        $this->assertCount(1, $collisions);
        $this->assertSame('clash', $collisions[0]['name']);
        $this->assertSame($winnerFile, $collisions[0]['winnerPath']);
        $this->assertSame($loserFile, $collisions[0]['loserPath']);
    }

    private function createRegistry(): ThemeRegistry
    {
        $resources = new AppResourceLocator($this->projectRoot);
        $appConfig = new AppConfig(tui: new TuiConfig('cyberpunk', []), logging: new LoggingConfig());

        return new ThemeRegistry($appConfig, $resources, new NullLogger());
    }

    private function createEmptyRegistry(): ThemeRegistry
    {
        // Point at an empty temp directory; registry will have no palettes.
        // getBuiltinThemesPath() returns $appRoot.'/config/themes', and since
        // the temp dir has no such subdirectory, loadDirectory() returns [].
        $resources = new AppResourceLocator($this->tempDir);
        $appConfig = new AppConfig(tui: new TuiConfig('cyberpunk', []), logging: new LoggingConfig());

        return new ThemeRegistry($appConfig, $resources, new NullLogger());
    }
}
