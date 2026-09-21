<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Theme;

use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ThemePalette::class)]
final class ThemePaletteTest extends TestCase
{
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
                'tool_argument_key' => 'warning',
                'warning' => '#ebcb8b',
                'tool_argument_value' => 'text',
            ],
        ];

        $palette = ThemePalette::fromArray($data);

        $this->assertSame('#4c566a', $palette->get(\Ineersa\Tui\Theme\ThemeColorEnum::Muted));
        $this->assertSame('', $palette->get(\Ineersa\Tui\Theme\ThemeColorEnum::Text));
        $this->assertSame('#ebcb8b', $palette->get(\Ineersa\Tui\Theme\ThemeColorEnum::ToolArgumentKey));
        $this->assertSame('', $palette->get(\Ineersa\Tui\Theme\ThemeColorEnum::ToolArgumentValue));
    }
}
