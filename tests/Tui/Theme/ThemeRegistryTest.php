<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Theme;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Theme\DefaultTheme;
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
        $this->projectRoot = ProjectDir::get();
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

    public function testBuiltinThemesResolveDistinctToolArgumentKeyColors(): void
    {
        $registry = $this->createRegistry();
        $themeNames = [
            'cyberpunk',
            'tokyo-night',
            'nord',
            'gruvbox-dark',
            'catppuccin-mocha',
            'oh-p-dark',
        ];

        foreach ($themeNames as $themeName) {
            $palette = $registry->getOrThrow($themeName);

            $argumentKey = $palette->get(ThemeColorEnum::ToolArgumentKey);
            $argumentValue = $palette->get(ThemeColorEnum::ToolArgumentValue);
            $muted = $palette->get(ThemeColorEnum::Muted);
            $toolTitle = $palette->get(ThemeColorEnum::ToolTitle);

            $this->assertNotNull($argumentKey, \sprintf('%s tool_argument_key must resolve', $themeName));
            $this->assertNotSame($muted, $argumentKey, \sprintf('%s tool_argument_key must differ from muted', $themeName));
            $this->assertNotSame($toolTitle, $argumentKey, \sprintf('%s tool_argument_key must differ from tool_title', $themeName));
            $this->assertNull($argumentValue, \sprintf('%s tool_argument_value stays default text', $themeName));
        }
    }

    public function testRegisterPaletteAddsNewTheme(): void
    {
        $registry = $this->createEmptyRegistry();
        $method = new \ReflectionMethod(ThemeRegistry::class, 'registerPalette');
        $method->invoke($registry, new ThemePalette('custom', ['accent' => '#abc']), '');

        $this->assertSame('#abc', $registry->getOrThrow('custom')->get(ThemeColorEnum::Accent));
    }

    public function testBuiltinThemePathsOverlapProducesNoSelfCollisions(): void
    {
        $resources = new AppResourceLocator($this->projectRoot);
        $builtin = $resources->getBuiltinThemesPath();
        $appConfig = new AppConfig(tui: new TuiConfig('cyberpunk', [$builtin]), logging: new LoggingConfig());
        $registry = new ThemeRegistry($appConfig, $resources, new NullLogger());

        $this->assertSame([], $registry->getThemeCollisions());
        $this->assertContains('nord', $registry->getNames());
    }

    public function testSameSourcePathDuplicateRegistrationProducesNoCollision(): void
    {
        $registry = $this->createEmptyRegistry();
        $themeFile = $this->tempDir.'/dup-theme.yaml';
        file_put_contents($themeFile, "name: dup-test\naccent: '#111111'\n");

        $palette = ThemePalette::fromArray(['name' => 'dup-test', 'accent' => '#111111']);
        $method = new \ReflectionMethod(ThemeRegistry::class, 'registerPalette');
        $method->invoke($registry, $palette, $themeFile);
        $method->invoke($registry, $palette, $themeFile);

        $this->assertSame([], $registry->getThemeCollisions());
        $this->assertContains('dup-test', $registry->getNames());
    }

    public function testDifferentSourcePathDuplicateNameRecordsCollision(): void
    {
        $registry = $this->createEmptyRegistry();
        $winnerFile = $this->tempDir.'/winner.yaml';
        $loserFile = $this->tempDir.'/loser.yaml';
        file_put_contents($winnerFile, "name: clash\naccent: '#111111'\n");
        file_put_contents($loserFile, "name: clash\naccent: '#222222'\n");

        $method = new \ReflectionMethod(ThemeRegistry::class, 'registerPalette');
        $method->invoke($registry, ThemePalette::fromArray(['name' => 'clash', 'accent' => '#111111']), $winnerFile);
        $method->invoke($registry, ThemePalette::fromArray(['name' => 'clash', 'accent' => '#222222']), $loserFile);

        $collisions = $registry->getThemeCollisions();
        $this->assertCount(1, $collisions);
        $this->assertSame('clash', $collisions[0]['name']);
        $this->assertSame($winnerFile, $collisions[0]['winnerPath']);
        $this->assertSame($loserFile, $collisions[0]['loserPath']);
    }

    public function testInvalidColorSpecsAreLoggedAndDroppedAtLoad(): void
    {
        file_put_contents($this->tempDir.'/broken.yaml', <<<'YAML'
            name: broken
            vars:
                count: 7
            colors:
                accent: "#00ffff"
                error: "not-a-colour"
                muted: "missing_var"
                warning: ~
                success: count
                text: ""
            YAML);
        $logger = new TestLogger();
        $resources = new AppResourceLocator($this->tempDir);
        $appConfig = new AppConfig(tui: new TuiConfig('broken', [$this->tempDir]), logging: new LoggingConfig());

        $palette = (new ThemeRegistry($appConfig, $resources, $logger))->getOrThrow('broken');

        $this->assertSame('#00ffff', $palette->get(ThemeColorEnum::Accent));
        foreach ([ThemeColorEnum::Error, ThemeColorEnum::Muted, ThemeColorEnum::Warning, ThemeColorEnum::Success, ThemeColorEnum::Text] as $token) {
            $this->assertNull($palette->get($token), $token->value);
        }
        $this->assertSame('plain', (new DefaultTheme($palette))->color(ThemeColorEnum::Error, 'plain'));

        $logged = array_map(
            static fn (array $record): string => $record['context']['section'].'.'.$record['context']['key'],
            array_filter($logger->records, static fn (array $record): bool => 'tui.theme.invalid_color' === ($record['context']['event_type'] ?? null)),
        );
        sort($logged);
        $this->assertSame(['colors.error', 'colors.muted', 'colors.success', 'colors.warning', 'vars.count'], $logged);
        foreach ($logger->records as $record) {
            $this->assertSame('warning', $record['level']);
            $this->assertSame('tui_theme', $record['context']['component']);
            $this->assertSame($this->tempDir.'/broken.yaml', $record['context']['file']);
        }
    }

    public function testMalformedThemeSectionsAreLoggedWithoutThrowing(): void
    {
        file_put_contents($this->tempDir.'/malformed.yaml', <<<'YAML'
            name: malformed
            vars: "abc"
            colors:
                - "not-a-colour"
            YAML);
        $logger = new TestLogger();
        $resources = new AppResourceLocator($this->tempDir);
        $appConfig = new AppConfig(tui: new TuiConfig('malformed', [$this->tempDir]), logging: new LoggingConfig());

        $palette = (new ThemeRegistry($appConfig, $resources, $logger))->getOrThrow('malformed');

        $this->assertNull($palette->get(ThemeColorEnum::Accent));
        $logged = array_map(
            static fn (array $record): string => $record['context']['section'].'.'.$record['context']['key'],
            array_filter($logger->records, static fn (array $record): bool => 'tui.theme.invalid_color' === ($record['context']['event_type'] ?? null)),
        );
        sort($logged);
        $this->assertSame(['colors.0', 'vars.'], $logged);
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
