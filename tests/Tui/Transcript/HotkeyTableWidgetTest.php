<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\HotkeyTableWidget;
use Ineersa\Tui\Transcript\ThemeStyleSheetFactory;
use Ineersa\Tui\Transcript\TranscriptBlockWidgetFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Widget\ContainerWidget;

#[CoversClass(HotkeyTableWidget::class)]
final class HotkeyTableWidgetTest extends TestCase
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
        $widget = new HotkeyTableWidget([], 'No hotkeys yet.');
        $output = implode("\n", $this->renderWidget($widget, 80));

        $this->assertStringContainsString('No hotkeys yet.', $output);
        $this->assertStringNotContainsString('┌', $output);
    }

    #[Test]
    public function rendersKeyboardShortcutsHeadingAndTable(): void
    {
        $widget = new HotkeyTableWidget([
            'Global' => [
                [
                    'keys' => ['ctrl+c'],
                    'action' => 'Clear editor',
                    'description' => 'Clear or double-exit',
                ],
            ],
        ]);
        $output = implode("\n", $this->renderWidget($widget, 100));

        $this->assertStringContainsString('Keyboard shortcuts', $output);
        $this->assertStringContainsString('┌', $output);
        $this->assertStringContainsString('│', $output);
        $this->assertStringContainsString('└', $output);
        $this->assertStringContainsString('Ctrl+C', $output);
        $this->assertStringContainsString('Clear editor', $output);
        $this->assertStringContainsString('Clear or double-exit', $output);
        $this->assertStringContainsString('Global', $output);
        $this->assertStringContainsString('App shortcuts (Ctrl+C, Ctrl+D) are global and cannot be remapped.', $output);

        // Style elements are registered for ChatScreen/Tui attachment (WidgetContext).
        // Standalone Renderer has no WidgetContext, so applyElement falls back to defaults.
        $sheet = (new ThemeStyleSheetFactory())->createHotkeyTable($this->theme->getPalette());
        $rules = (new \ReflectionProperty($sheet, 'rules'))->getValue($sheet);
        $this->assertArrayHasKey(HotkeyTableWidget::class.'::heading', $rules);
        $this->assertArrayHasKey(HotkeyTableWidget::class.'::key', $rules);
        $this->assertArrayHasKey(HotkeyTableWidget::class.'::border', $rules);
    }

    #[Test]
    public function formatKeyDisplayConvertsIdentifiers(): void
    {
        $this->assertSame('Ctrl+C', HotkeyTableWidget::formatKeyDisplay('ctrl+c'));
        $this->assertSame('Shift+Enter', HotkeyTableWidget::formatKeyDisplay('shift+enter'));
        $this->assertSame('↑', HotkeyTableWidget::formatKeyDisplay('up'));
        $this->assertSame('↓', HotkeyTableWidget::formatKeyDisplay('down'));
        $this->assertSame('←', HotkeyTableWidget::formatKeyDisplay('left'));
        $this->assertSame('→', HotkeyTableWidget::formatKeyDisplay('right'));
        $this->assertSame('Esc', HotkeyTableWidget::formatKeyDisplay('escape'));
        $this->assertSame('Tab', HotkeyTableWidget::formatKeyDisplay('tab'));
        $this->assertSame('Space', HotkeyTableWidget::formatKeyDisplay('space'));
        $this->assertSame('Enter', HotkeyTableWidget::formatKeyDisplay('enter'));
        $this->assertSame('Ctrl+Alt+Del', HotkeyTableWidget::formatKeyDisplay('ctrl+alt+delete'));
        $this->assertSame('F1', HotkeyTableWidget::formatKeyDisplay('f1'));
    }

    #[Test]
    public function everyRowFitsPathologicalNarrowWidth(): void
    {
        $widget = new HotkeyTableWidget([
            'Editor' => [
                [
                    'keys' => ['ctrl+shift+enter'],
                    'action' => 'A very long action name that should truncate',
                    'description' => 'A very long description that also needs truncation under narrow widths',
                ],
            ],
        ]);

        foreach ([10, 20, 40, 80] as $width) {
            $lines = $this->renderWidget($widget, $width);
            foreach ($lines as $i => $line) {
                $this->assertStringNotContainsString("\n", $line, "row {$i} at width {$width}");
                $this->assertLessThanOrEqual(
                    $width,
                    AnsiUtils::visibleWidth($line),
                    "row {$i} visible width exceeds {$width}: ".preg_replace('/\e\[[0-9;]*m/', '', $line),
                );
            }
            $joined = implode("\n", $lines);
            // Full heading only fits wider terminals; narrow widths truncate via fitLine().
            $this->assertStringContainsString(
                $width >= 20 ? 'Keyboard shortcuts' : 'Keyboard',
                $joined,
            );
        }
    }

    #[Test]
    public function sameWidgetReflowsOnResize(): void
    {
        $widget = new HotkeyTableWidget([
            'Global' => [
                ['keys' => ['ctrl+c'], 'action' => 'Cancel', 'description' => 'Interrupt'],
            ],
        ]);

        $wide = $this->renderWidget($widget, 120);
        $narrow = $this->renderWidget($widget, 30);

        $this->assertNotSame(implode("\n", $wide), implode("\n", $narrow));
        foreach ($narrow as $line) {
            $this->assertLessThanOrEqual(30, AnsiUtils::visibleWidth($line));
        }
        $this->assertStringContainsString('Ctrl+C', implode("\n", $narrow));
    }

    #[Test]
    public function longKeysTruncateUnderNarrowWidth(): void
    {
        $widget = new HotkeyTableWidget([
            'Global' => [
                [
                    'keys' => ['ctrl+alt+shift+page_down'],
                    'action' => 'Do something elaborate',
                    'description' => 'Needs room',
                ],
            ],
        ]);

        $joined = implode("\n", $this->renderWidget($widget, 28));
        $this->assertStringContainsString('…', $joined);
        $this->assertStringContainsString('Keyboard shortcuts', $joined);
    }

    #[Test]
    public function factoryBuildsHotkeyTableWidgetFromStructuredMeta(): void
    {
        $block = new TranscriptBlock(
            id: 'tui_system_run_1',
            kind: TranscriptBlockKindEnum::System,
            runId: 'run',
            seq: 1,
            text: '',
            meta: [
                'style' => 'hotkey-table',
                'hotkey_groups' => [
                    'Global' => [
                        ['keys' => ['ctrl+d'], 'action' => 'Exit', 'description' => 'Quit'],
                    ],
                ],
                'empty_message' => '',
            ],
        );

        $widget = (new TranscriptBlockWidgetFactory())->buildWidget($block, $this->theme);
        $this->assertInstanceOf(HotkeyTableWidget::class, $widget);

        $lines = $this->renderWidget($widget, 100);
        $joined = implode("\n", $lines);
        $this->assertStringContainsString('Keyboard shortcuts', $joined);
        $this->assertStringContainsString('Ctrl+D', $joined);
        $this->assertStringContainsString('Exit', $joined);
        $this->assertSame('', $block->text, 'hotkey-table block stores structured meta, not pre-rendered ANSI');
    }

    /**
     * @return list<string>
     */
    private function renderWidget(HotkeyTableWidget $widget, int $width): array
    {
        $root = new ContainerWidget();
        $root->add($widget);
        $renderer = new Renderer((new ThemeStyleSheetFactory())->createHotkeyTable($this->theme->getPalette()));

        return $renderer->render($root, $width, 40);
    }
}
