<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Theme;

use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ThemePalette::class)]
final class ThemePaletteTest extends TestCase
{
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

        $this->assertSame('alias-test', $palette->name);
        $this->assertSame('#ebcb8b', $palette->get(ThemeColorEnum::Warning));
        $this->assertSame('#4c566a', $palette->get(ThemeColorEnum::Muted));
        $this->assertNull($palette->get(ThemeColorEnum::Text));
        $this->assertSame('#ebcb8b', $palette->get(ThemeColorEnum::ToolArgumentKey));
        $this->assertNull($palette->get(ThemeColorEnum::ToolArgumentValue));
        $this->assertNull($palette->get(ThemeColorEnum::ThinkingMax));
    }
}
