<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Transcript;

use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\ToolArgumentColoredFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolArgumentColoredFormatter::class)]
final class ToolArgumentColoredFormatterTest extends TestCase
{
    public function testFormatColoredLinesUsesToolArgumentKeyAndValueTokensNotMutedText(): void
    {
        $theme = new DefaultTheme(new ThemePalette('formatter-test', [
            ThemeColorEnum::Muted->value => '#111111',
            ThemeColorEnum::Text->value => '#222222',
            ThemeColorEnum::ToolArgumentKey->value => '#ff00aa',
            ThemeColorEnum::ToolArgumentValue->value => '#00ffaa',
        ]));

        $lines = (new ToolArgumentColoredFormatter())->formatColoredLines(
            ['path' => './example.txt', 'max_bytes' => 64],
            $theme,
        );

        $this->assertNotSame([], $lines);

        $joined = implode("\n", $lines);
        $this->assertStringContainsString('path', $joined);
        $this->assertStringContainsString('./example.txt', $joined);

        $keyAnsi = $theme->color(ThemeColorEnum::ToolArgumentKey, 'path');
        $valueAnsi = $theme->color(ThemeColorEnum::ToolArgumentValue, ' ./example.txt');
        $mutedAnsi = $theme->muted('path');
        $textAnsi = $theme->text(' ./example.txt');

        $this->assertStringContainsString($keyAnsi, $joined);
        $this->assertStringContainsString($valueAnsi, $joined);
        $this->assertStringNotContainsString($mutedAnsi, $joined);
        $this->assertStringNotContainsString($textAnsi, $joined);
        $this->assertNotSame($keyAnsi, $mutedAnsi);
        $this->assertNotSame($valueAnsi, $textAnsi);
    }
}
