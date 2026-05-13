<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Theme;

use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Theme\ThemeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ThemeRegistry::class)]
final class ThemeRegistryTest extends TestCase
{
    public function testDefaultThemeIsCyberpunk(): void
    {
        $registry = new ThemeRegistry(
            builtin: [
                new ThemePalette('cyberpunk', ['accent' => '#00ffff']),
                new ThemePalette('nord', ['accent' => '#88c0d0']),
            ],
            defaultName: 'cyberpunk',
        );

        $theme = $registry->getDefault();

        self::assertSame('cyberpunk', $theme->name);
        self::assertSame('#00ffff', $theme->get(\Ineersa\Tui\Theme\ThemeColor::Accent));
    }

    public function testGetOrDefaultReturnsDefaultWhenMissing(): void
    {
        $registry = new ThemeRegistry(
            builtin: [new ThemePalette('cyberpunk', ['accent' => '#00ffff'])],
            defaultName: 'cyberpunk',
        );

        $theme = $registry->getOrDefault('nonexistent');

        self::assertSame('cyberpunk', $theme->name);
    }

    public function testGetOrDefaultReturnsExactWhenFound(): void
    {
        $registry = new ThemeRegistry(
            builtin: [
                new ThemePalette('cyberpunk', ['accent' => '#00ffff']),
                new ThemePalette('nord', ['accent' => '#88c0d0']),
            ],
            defaultName: 'cyberpunk',
        );

        $theme = $registry->getOrDefault('nord');

        self::assertSame('nord', $theme->name);
        self::assertSame('#88c0d0', $theme->get(\Ineersa\Tui\Theme\ThemeColor::Accent));
    }

    public function testHas(): void
    {
        $registry = new ThemeRegistry(
            builtin: [new ThemePalette('cyberpunk')],
        );

        self::assertTrue($registry->has('cyberpunk'));
        self::assertFalse($registry->has('nord'));
    }

    public function testGetNames(): void
    {
        $registry = new ThemeRegistry(
            builtin: [
                new ThemePalette('cyberpunk'),
                new ThemePalette('nord'),
            ],
        );

        $names = $registry->getNames();

        self::assertContains('cyberpunk', $names);
        self::assertContains('nord', $names);
        self::assertCount(2, $names);
    }

    public function testRegisterAddsNewTheme(): void
    {
        $registry = new ThemeRegistry(builtin: []);
        $registry->register(new ThemePalette('custom', ['accent' => '#abc']));

        self::assertTrue($registry->has('custom'));
        self::assertSame('#abc', $registry->get('custom')?->get(\Ineersa\Tui\Theme\ThemeColor::Accent));
    }

    public function testGetReturnsNullForMissing(): void
    {
        $registry = new ThemeRegistry(builtin: []);

        self::assertNull($registry->get('nope'));
    }

    public function testDefaultName(): void
    {
        $registry = new ThemeRegistry(defaultName: 'tokyo-night');

        self::assertSame('tokyo-night', $registry->getDefaultName());
    }
}
