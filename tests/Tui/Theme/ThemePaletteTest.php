<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Theme;

use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ThemePalette::class)]
final class ThemePaletteTest extends TestCase
{
    public function testName(): void
    {
        $palette = new ThemePalette('test');

        $this->assertSame('test', $palette->name);
    }

    public function testGetKnownColor(): void
    {
        $palette = new ThemePalette('test', ['accent' => '#ff00ff']);

        $this->assertSame('#ff00ff', $palette->get(\Ineersa\Tui\Theme\ThemeColorEnum::Accent));
    }

    public function testGetMissingColorReturnsEmpty(): void
    {
        $palette = new ThemePalette('test', []);

        $this->assertSame('', $palette->get(\Ineersa\Tui\Theme\ThemeColorEnum::Accent));
    }

    public function testHasReturnsTrueForNonEmpty(): void
    {
        $palette = new ThemePalette('test', ['accent' => 'cyan']);

        $this->assertTrue($palette->has(\Ineersa\Tui\Theme\ThemeColorEnum::Accent));
    }

    public function testHasReturnsFalseForEmpty(): void
    {
        $palette = new ThemePalette('test', []);

        $this->assertFalse($palette->has(\Ineersa\Tui\Theme\ThemeColorEnum::Accent));
    }

    public function testWithOverrides(): void
    {
        $palette = new ThemePalette('test', ['accent' => 'cyan']);

        $overridden = $palette->withOverrides(['accent' => 'magenta', 'muted' => '#888']);

        $this->assertSame('test', $overridden->name);
        $this->assertSame('magenta', $overridden->get(\Ineersa\Tui\Theme\ThemeColorEnum::Accent));
        $this->assertSame('#888', $overridden->get(\Ineersa\Tui\Theme\ThemeColorEnum::Muted));
        // Original unchanged
        $this->assertSame('cyan', $palette->get(\Ineersa\Tui\Theme\ThemeColorEnum::Accent));
    }

    public function testFromArray(): void
    {
        $data = [
            'name' => 'cyberpunk',
            'vars' => [
                'neon' => '#ff00ff',
            ],
            'colors' => [
                'accent' => 'neon',
                'text' => '',
                'muted' => '#718096',
            ],
        ];

        $palette = ThemePalette::fromArray($data);

        $this->assertSame('cyberpunk', $palette->name);
        // Var 'neon' resolves to #ff00ff
        $this->assertSame('#ff00ff', $palette->get(\Ineersa\Tui\Theme\ThemeColorEnum::Accent));
        // Empty stays empty
        $this->assertSame('', $palette->get(\Ineersa\Tui\Theme\ThemeColorEnum::Text));
        // Direct hex stays
        $this->assertSame('#718096', $palette->get(\Ineersa\Tui\Theme\ThemeColorEnum::Muted));
    }

    public function testFromArrayWithoutVars(): void
    {
        $data = [
            'name' => 'simple',
            'colors' => [
                'accent' => '#abc',
            ],
        ];

        $palette = ThemePalette::fromArray($data);

        $this->assertSame('simple', $palette->name);
        $this->assertSame('#abc', $palette->get(\Ineersa\Tui\Theme\ThemeColorEnum::Accent));
    }

    public function testFromArrayResolvesColorTokenAliases(): void
    {
        $data = [
            'name' => 'alias-test',
            'vars' => [
                'nord3' => '#4c566a',
            ],
            'colors' => [
                'muted' => 'nord3',
                'text' => '',
                'tool_argument_key' => 'muted',
                'tool_argument_value' => 'text',
            ],
        ];

        $palette = ThemePalette::fromArray($data);

        $this->assertSame('#4c566a', $palette->get(\Ineersa\Tui\Theme\ThemeColorEnum::Muted));
        $this->assertSame('', $palette->get(\Ineersa\Tui\Theme\ThemeColorEnum::Text));
        $this->assertSame('#4c566a', $palette->get(\Ineersa\Tui\Theme\ThemeColorEnum::ToolArgumentKey));
        $this->assertSame('', $palette->get(\Ineersa\Tui\Theme\ThemeColorEnum::ToolArgumentValue));
    }

    public function testFromArrayDefaultName(): void
    {
        $palette = ThemePalette::fromArray(['colors' => []]);

        $this->assertSame('unnamed', $palette->name);
    }
}
