<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Widget;

use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\TestCase;

final class BackgroundWorkWidgetTest extends TestCase
{
    public function testRealisticChildDetailsCannotDisplaceStatusModelOrContext(): void
    {
        foreach ([80, 120] as $width) {
            $harness = new VirtualTuiHarness(columns: $width, rows: 20);
            $harness->screen()->setBackgroundRows([[
                'kind' => 'agent',
                'label' => 'scout — '.str_repeat('long task ', 8).' · agent_1234567890abcdef run:12345678-1234',
                'status' => '⚠ needs input',
                'suffix' => '9% 23.4k/272.0k gpt-6.1-sol',
                'needs_input' => true,
            ]]);
            $text = $harness->plainScreenText();
            $this->assertStringContainsString('⚠ needs input', $text);
            $this->assertStringContainsString('9% 23.4k/272.0k gpt-6.1-sol', $text);
            $this->assertStringContainsString('scout', $text);
            $this->assertStringContainsString('long task', $text);
            $this->assertStringContainsString('…', $text);
        }
    }

    public function testOverflowIsFivePhysicalRowsWithStableAttentionPriorityAndResponsiveBudget(): void
    {
        $harness = new VirtualTuiHarness(columns: 48, rows: 12, sessionId: 'overflow');
        $screen = $harness->screen();
        $screen->promptEditor()->setText('EDITOR_ANCHOR');
        $rows = [];
        for ($index = 0; $index < 16; ++$index) {
            $rows[] = ['kind' => 'agent', 'label' => 'ITEM_'.$index.':'.($index >= 14 ? ' [needs input]' : ''), 'status' => '', 'needs_input' => $index >= 14];
        }
        $screen->setBackgroundRows($rows);
        $text = $harness->plainScreenText();
        $this->assertStringContainsString('EDITOR_ANCHOR', $text);
        $this->assertStringContainsString('session overflow', $text);
        $this->assertStringContainsString('+12 more · /bg or Ctrl+\\', $text);
        $this->assertSame(4, substr_count($text, 'ITEM_'));
        $this->assertLessThan(strpos($text, 'ITEM_15'), strpos($text, 'ITEM_14'));
        $this->assertLessThan(strpos($text, 'ITEM_0'), strpos($text, 'ITEM_15'));
        $this->assertLessThan(strpos($text, 'ITEM_1:'), strpos($text, 'ITEM_0:'));
        $harness->terminal()->simulateResize(48, 7);
        $text = $harness->plainScreenText();
        $this->assertStringContainsString('EDITOR_ANCHOR', $text);
        $this->assertStringContainsString('session overflow', $text);
        $this->assertStringContainsString('+16 more', $text);
        $this->assertStringNotContainsString('ITEM_', $text);
        $harness->terminal()->simulateResize(48, 30);
        $this->assertStringContainsString('+12 more', $harness->plainScreenText());
        $harness->terminal()->simulateResize(20, 12);
        $text = $harness->plainScreenText();
        $this->assertStringContainsString('/bg Ctrl+\\', $text);
        $harness->terminal()->simulateResize(48, 12);
        $screen->promptEditor()->setText("EDITOR_ANCHOR\nline2\nline3\nline4\nline5");
        $text = $harness->plainScreenText();
        $this->assertStringContainsString('EDITOR_ANCHOR', $text);
        $this->assertStringContainsString('session overflow', $text);
        $this->assertLessThan(4, substr_count($text, 'ITEM_'));
        $screen->promptEditor()->setText('EDITOR_ANCHOR');
        $screen->setBackgroundRows(\array_slice($rows, 12));
        $text = $harness->plainScreenText();
        $this->assertStringNotContainsString('more', $text);
        $this->assertLessThan(strpos($text, 'ITEM_14'), strpos($text, 'ITEM_12'));
    }

    public function testOverflowMetadataChangesUseNativeDiffAndIdenticalRowsProduceNoOutput(): void
    {
        $harness = new VirtualTuiHarness(columns: 48, rows: 12);
        $rows = array_fill(0, 16, ['kind' => 'agent', 'label' => 'TASK', 'status' => 'running', 'suffix' => '7% 19.3k']);
        $screen = $harness->screen();
        $screen->promptEditor()->setText('UNCHANGED_EDITOR');
        $screen->setBackgroundRows($rows);
        $harness->render();
        $harness->terminal()->clearOutput();
        $rows[0]['suffix'] = '9% 23.4k';
        $harness->tui()->onTick(static fn () => $screen->setBackgroundRows($rows));
        $harness->tui()->tick();
        $harness->tui()->processRender();
        $output = $harness->terminal()->consumeOutput();
        $this->assertStringContainsString('23.4k', $output);
        $this->assertStringNotContainsString('UNCHANGED_EDITOR', $output);
        $this->assertStringNotContainsString('more', $output);
        $this->assertStringNotContainsString("\033[2J", $output);
        $this->assertStringNotContainsString("\033[H", $output);
        $harness->tui()->tick();
        $harness->tui()->processRender();
        $this->assertSame('', $harness->terminal()->consumeOutput());
    }
}
