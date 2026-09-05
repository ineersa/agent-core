<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Transcript;

use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\ToolArgumentColoredFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
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
        $textAnsi = $theme->color(ThemeColorEnum::Text, ' ./example.txt');

        $this->assertStringContainsString($keyAnsi, $joined);
        $this->assertStringContainsString($valueAnsi, $joined);
        $this->assertStringNotContainsString($mutedAnsi, $joined);
        $this->assertStringNotContainsString($textAnsi, $joined);
        $this->assertNotSame($keyAnsi, $mutedAnsi);
        $this->assertNotSame($valueAnsi, $textAnsi);
    }

    #[Test]
    public function formatsEachTopLevelArgumentAsOneLogicalLineWithoutDroppingValues(): void
    {
        $theme = new DefaultTheme(new ThemePalette('formatter-test', [
            ThemeColorEnum::ToolArgumentKey->value => '#ff00aa',
            ThemeColorEnum::ToolArgumentValue->value => '#00ffaa',
        ]));

        $lines = (new ToolArgumentColoredFormatter())->formatColoredLines([
            'nested' => ['items' => [1, 2], 'enabled' => true],
            'multiline' => "first\nsecond",
            'long' => str_repeat('x', 300).'tail-marker',
        ], $theme);

        $this->assertCount(3, $lines);
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', implode("\n", $lines));
        $this->assertIsString($plain);
        $this->assertStringContainsString('nested: { items: [1, 2], enabled: true }', $plain);
        $this->assertStringContainsString('multiline: "first\\nsecond"', $plain);
        $this->assertStringContainsString('tail-marker', $plain);
    }
}
