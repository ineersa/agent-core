<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Transcript;

use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\HotkeyTableRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HotkeyTableRenderer::class)]
final class HotkeyTableRendererTest extends TestCase
{
    private DefaultTheme $theme;

    protected function setUp(): void
    {
        $this->theme = new DefaultTheme(new ThemePalette(
            name: 'test',
            colors: [
                'accent' => '#8abeb7',
                'muted' => '#6a6a7a',
                'success' => '#b5bd68',
            ],
        ));
    }

    #[Test]
    public function emptyGroupsReturnsMutedMessage(): void
    {
        $renderer = new HotkeyTableRenderer();
        $output = $renderer->render([], $this->theme, 'No hotkeys yet.');

        // Should contain the muted message
        self::assertStringContainsString('No hotkeys yet.', $output);
        // Should NOT contain box-drawing chars (no tables rendered)
        self::assertStringNotContainsString('┌', $output);
        self::assertStringNotContainsString('│', $output);
    }

    #[Test]
    public function rendersKeyboardShortcutsHeadingWithAccentColor(): void
    {
        $renderer = new HotkeyTableRenderer();
        $output = $renderer->render([
            'Global' => [
                [
                    'keys' => ['ctrl+c'],
                    'action' => 'Clear editor',
                    'description' => 'Clear or double-exit',
                ],
            ],
        ], $this->theme);

        // Must contain the heading
        self::assertStringContainsString('Keyboard shortcuts', $output);

        // Must contain box-drawing table chars
        self::assertStringContainsString('┌', $output);
        self::assertStringContainsString('│', $output);
        self::assertStringContainsString('└', $output);

        // Must contain the hotkey data
        self::assertStringContainsString('Ctrl+C', $output);
        self::assertStringContainsString('Clear editor', $output);
        self::assertStringContainsString('Clear or double-exit', $output);

        // Must contain the accent-styled heading (ANSI codes from theme)
        // The heading text should have ANSI sequences around it
        $accentedHeading = $this->theme->accent('Keyboard shortcuts');
        self::assertStringContainsString($accentedHeading, $output);

        // Must contain the accent-styled context name
        $accentedGlobal = $this->theme->accent('Global');
        self::assertStringContainsString($accentedGlobal, $output);

        // Must contain muted footer
        $mutedFooter = $this->theme->muted(
            'App shortcuts (Ctrl+C, Ctrl+D) are global and cannot be remapped.',
        );
        self::assertStringContainsString($mutedFooter, $output);
    }

    #[Test]
    public function rendersMultipleContextsWithAnsiStyling(): void
    {
        $renderer = new HotkeyTableRenderer();
        $output = $renderer->render([
            'Global' => [
                ['keys' => ['ctrl+c'], 'action' => 'Cancel', 'description' => ''],
                ['keys' => ['ctrl+d'], 'action' => 'Exit', 'description' => ''],
            ],
            'Editor' => [
                ['keys' => ['enter'], 'action' => 'Submit prompt', 'description' => 'Send to model'],
                ['keys' => ['ctrl+j'], 'action' => 'Insert newline', 'description' => 'Multiline'],
            ],
        ], $this->theme);

        // Both context names should appear
        self::assertStringContainsString('Global', $output);
        self::assertStringContainsString('Editor', $output);

        // All hotkeys should appear with formatted key display
        self::assertStringContainsString('Ctrl+C', $output);
        self::assertStringContainsString('Ctrl+D', $output);
        self::assertStringContainsString('Enter', $output);
        self::assertStringContainsString('Ctrl+J', $output);

        // Action names
        self::assertStringContainsString('Submit prompt', $output);
        self::assertStringContainsString('Insert newline', $output);

        // Check that ANSI-styled key text has accent/success sequences
        // (the themed accent/success tokens wrap key/action names)
        // Verify by stripping ANSI and checking plain content still present
        $stripped = preg_replace('/\e\[[0-9;]*m/', '', $output);
        self::assertStringContainsString('Keyboard shortcuts', $stripped);
        self::assertStringContainsString('Global', $stripped);
        self::assertStringContainsString('Editor', $stripped);
        self::assertStringContainsString('Ctrl+C', $stripped);
        self::assertStringContainsString('Submit prompt', $stripped);
    }

    #[Test]
    public function formatKeyDisplayConvertsIdentifiers(): void
    {
        self::assertSame('Ctrl+C', HotkeyTableRenderer::formatKeyDisplay('ctrl+c'));
        self::assertSame('Shift+Enter', HotkeyTableRenderer::formatKeyDisplay('shift+enter'));
        self::assertSame('↑', HotkeyTableRenderer::formatKeyDisplay('up'));
        self::assertSame('↓', HotkeyTableRenderer::formatKeyDisplay('down'));
        self::assertSame('←', HotkeyTableRenderer::formatKeyDisplay('left'));
        self::assertSame('→', HotkeyTableRenderer::formatKeyDisplay('right'));
        self::assertSame('Esc', HotkeyTableRenderer::formatKeyDisplay('escape'));
        self::assertSame('Tab', HotkeyTableRenderer::formatKeyDisplay('tab'));
        self::assertSame('Space', HotkeyTableRenderer::formatKeyDisplay('space'));
        self::assertSame('Enter', HotkeyTableRenderer::formatKeyDisplay('enter'));
        self::assertSame('Ctrl+Alt+Del', HotkeyTableRenderer::formatKeyDisplay('ctrl+alt+delete'));
        self::assertSame('F1', HotkeyTableRenderer::formatKeyDisplay('f1'));
    }

    #[Test]
    public function padDisplayWidthPadsCorrectly(): void
    {
        self::assertSame('abc   ', HotkeyTableRenderer::padDisplayWidth('abc', 6));
        self::assertSame('abc', HotkeyTableRenderer::padDisplayWidth('abc', 3));
        self::assertSame('abc', HotkeyTableRenderer::padDisplayWidth('abc', 1));
        // Multi-byte: ↑ is 3 bytes but 1 display column
        self::assertSame('↑  ', HotkeyTableRenderer::padDisplayWidth('↑', 3));
    }

    #[Test]
    public function truncPadDisplayWidthTruncatesLongStrings(): void
    {
        $long = 'This is a very long string that should be truncated';
        $result = HotkeyTableRenderer::truncPadDisplayWidth($long, 20);
        self::assertLessThanOrEqual(20, mb_strwidth($result));
        self::assertStringEndsWith('…', rtrim($result));
    }

    #[Test]
    public function coloredOutputContainsAnsiEscapeSequences(): void
    {
        $renderer = new HotkeyTableRenderer();
        $output = $renderer->render([
            'Editor' => [
                ['keys' => ['ctrl+j'], 'action' => 'Insert newline', 'description' => ''],
            ],
        ], $this->theme);

        // The output must contain ANSI escape sequences — theme colors are active
        self::assertMatchesRegularExpression('/\e\[[0-9;]+m/', $output);

        // Verify that after stripping ANSI, the plain content matches
        $stripped = preg_replace('/\e\[[0-9;]*m/', '', $output);
        self::assertStringContainsString('Ctrl+J', $stripped);
        self::assertStringContainsString('Insert newline', $stripped);
    }
}
