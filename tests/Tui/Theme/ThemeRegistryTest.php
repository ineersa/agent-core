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
    public function testGetOrThrowReturnsThemeWhenFound(): void
    {
        $registry = new ThemeRegistry(
            builtin: [
                new ThemePalette('cyberpunk', ['accent' => '#00ffff']),
                new ThemePalette('nord', ['accent' => '#88c0d0']),
            ],
        );

        $theme = $registry->getOrThrow('cyberpunk');

        self::assertSame('cyberpunk', $theme->name);
        self::assertSame('#00ffff', $theme->get(\Ineersa\Tui\Theme\ThemeColor::Accent));
    }

    public function testGetOrThrowThrowsWhenMissing(): void
    {
        $registry = new ThemeRegistry(
            builtin: [new ThemePalette('cyberpunk', ['accent' => '#00ffff'])],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Theme "nonexistent" is not registered');

        $registry->getOrThrow('nonexistent');
    }

    public function testGetOrThrowReturnsExactWhenFound(): void
    {
        $registry = new ThemeRegistry(
            builtin: [
                new ThemePalette('cyberpunk', ['accent' => '#00ffff']),
                new ThemePalette('nord', ['accent' => '#88c0d0']),
            ],
        );

        $theme = $registry->getOrThrow('nord');

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
        $registry = new ThemeRegistry(
            builtin: [
                new ThemePalette('cyberpunk'),
                new ThemePalette('tokyo-night'),
            ],
        );

        self::assertTrue($registry->has('tokyo-night'));
        $theme = $registry->getOrThrow('tokyo-night');
        self::assertSame('tokyo-night', $theme->name);
    }
}
