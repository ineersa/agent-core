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
        $this->assertStringContainsString('No hotkeys yet.', $output);
        // Should NOT contain box-drawing chars (no tables rendered)
        $this->assertStringNotContainsString('┌', $output);
        $this->assertStringNotContainsString('│', $output);
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
        $this->assertStringContainsString('Keyboard shortcuts', $output);

        // Must contain box-drawing table chars
        $this->assertStringContainsString('┌', $output);
        $this->assertStringContainsString('│', $output);
        $this->assertStringContainsString('└', $output);

        // Must contain the hotkey data
        $this->assertStringContainsString('Ctrl+C', $output);
        $this->assertStringContainsString('Clear editor', $output);
        $this->assertStringContainsString('Clear or double-exit', $output);

        // Must contain the accent-styled heading (ANSI codes from theme)
        // The heading text should have ANSI sequences around it
        $accentedHeading = $this->theme->accent('Keyboard shortcuts');
        $this->assertStringContainsString($accentedHeading, $output);

        // Must contain the accent-styled context name
        $accentedGlobal = $this->theme->accent('Global');
        $this->assertStringContainsString($accentedGlobal, $output);

        // Must contain muted footer
        $mutedFooter = $this->theme->muted(
            'App shortcuts (Ctrl+C, Ctrl+D) are global and cannot be remapped.',
        );
        $this->assertStringContainsString($mutedFooter, $output);
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
        $this->assertStringContainsString('Global', $output);
        $this->assertStringContainsString('Editor', $output);

        // All hotkeys should appear with formatted key display
        $this->assertStringContainsString('Ctrl+C', $output);
        $this->assertStringContainsString('Ctrl+D', $output);
        $this->assertStringContainsString('Enter', $output);
        $this->assertStringContainsString('Ctrl+J', $output);

        // Action names
        $this->assertStringContainsString('Submit prompt', $output);
        $this->assertStringContainsString('Insert newline', $output);

        // Check that ANSI-styled key text has accent/success sequences
        // (the themed accent/success tokens wrap key/action names)
        // Verify by stripping ANSI and checking plain content still present
        $stripped = preg_replace('/\e\[[0-9;]*m/', '', $output);
        $this->assertStringContainsString('Keyboard shortcuts', $stripped);
        $this->assertStringContainsString('Global', $stripped);
        $this->assertStringContainsString('Editor', $stripped);
        $this->assertStringContainsString('Ctrl+C', $stripped);
        $this->assertStringContainsString('Submit prompt', $stripped);
    }

    #[Test]
    public function formatKeyDisplayConvertsIdentifiers(): void
    {
        $this->assertSame('Ctrl+C', HotkeyTableRenderer::formatKeyDisplay('ctrl+c'));
        $this->assertSame('Shift+Enter', HotkeyTableRenderer::formatKeyDisplay('shift+enter'));
        $this->assertSame('↑', HotkeyTableRenderer::formatKeyDisplay('up'));
        $this->assertSame('↓', HotkeyTableRenderer::formatKeyDisplay('down'));
        $this->assertSame('←', HotkeyTableRenderer::formatKeyDisplay('left'));
        $this->assertSame('→', HotkeyTableRenderer::formatKeyDisplay('right'));
        $this->assertSame('Esc', HotkeyTableRenderer::formatKeyDisplay('escape'));
        $this->assertSame('Tab', HotkeyTableRenderer::formatKeyDisplay('tab'));
        $this->assertSame('Space', HotkeyTableRenderer::formatKeyDisplay('space'));
        $this->assertSame('Enter', HotkeyTableRenderer::formatKeyDisplay('enter'));
        $this->assertSame('Ctrl+Alt+Del', HotkeyTableRenderer::formatKeyDisplay('ctrl+alt+delete'));
        $this->assertSame('F1', HotkeyTableRenderer::formatKeyDisplay('f1'));
    }

    #[Test]
    public function padDisplayWidthPadsCorrectly(): void
    {
        $this->assertSame('abc   ', HotkeyTableRenderer::padDisplayWidth('abc', 6));
        $this->assertSame('abc', HotkeyTableRenderer::padDisplayWidth('abc', 3));
        $this->assertSame('abc', HotkeyTableRenderer::padDisplayWidth('abc', 1));
        // Multi-byte: ↑ is 3 bytes but 1 display column
        $this->assertSame('↑  ', HotkeyTableRenderer::padDisplayWidth('↑', 3));
    }

    #[Test]
    public function truncPadDisplayWidthTruncatesLongStrings(): void
    {
        $long = 'This is a very long string that should be truncated';
        $result = HotkeyTableRenderer::truncPadDisplayWidth($long, 20);
        $this->assertLessThanOrEqual(20, mb_strwidth($result));
        $this->assertStringEndsWith('…', rtrim($result));
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
        $this->assertMatchesRegularExpression('/\e\[[0-9;]+m/', $output);

        // Verify that after stripping ANSI, the plain content matches
        $stripped = preg_replace('/\e\[[0-9;]*m/', '', $output);
        $this->assertStringContainsString('Ctrl+J', $stripped);
        $this->assertStringContainsString('Insert newline', $stripped);
    }
}
