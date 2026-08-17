<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\CompactHeader;

use Ineersa\Tui\CompactHeader\CompactHeaderSnapshot;
use Ineersa\Tui\CompactHeader\CompactHeaderWidget;
use Ineersa\Tui\CompactHeader\McpServerHeaderEntry;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\DefaultTheme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Widget\ContainerWidget;

final class CompactHeaderWidgetTest extends TestCase
{
    #[Test]
    public function emptySnapshotRendersZeroLines(): void
    {
        $widget = new CompactHeaderWidget($this->theme());
        $widget->setSnapshot(new CompactHeaderSnapshot());

        $this->assertSame([], $this->renderWidget($widget, 80));
    }

    #[Test]
    public function rendersPromptsSkillsAgentsAndMcpSections(): void
    {
        $widget = new CompactHeaderWidget($this->theme());
        $widget->setSnapshot(new CompactHeaderSnapshot(
            prompts: ['review'],
            skills: ['castor'],
            agentNames: ['scout', 'worker'],
            mcpServers: [
                new McpServerHeaderEntry('context7', 2, true, true),
                new McpServerHeaderEntry('websearch', 3, true, false),
                new McpServerHeaderEntry('broken', null, false, true),
            ],
        ));

        $plain = $this->plainLines($this->renderWidget($widget, 120));

        $this->assertStringContainsString('prompts', $plain);
        $this->assertStringContainsString('│', $plain);
        $this->assertStringContainsString('/review', $plain);
        $this->assertStringContainsString('castor', $plain);
        $this->assertStringNotContainsString('skill:', $plain);
        $this->assertStringContainsString('agents', $plain);
        $this->assertStringContainsString('scout', $plain);
        $this->assertStringContainsString('worker', $plain);
        $this->assertStringNotContainsString('available', $plain);
        $this->assertStringNotContainsString('/agents-live', $plain);
        $this->assertStringContainsString('context7', $plain);
        $this->assertStringContainsString('websearch', $plain);
        $this->assertStringContainsString('(2)', $plain);
        $this->assertStringContainsString('(3)', $plain);
        $this->assertStringContainsString('✓', $plain);
        $this->assertStringContainsString('◈', $plain);
        $this->assertStringContainsString('✗', $plain);
        $this->assertStringNotContainsString(': connected', $plain);
        $this->assertStringNotContainsString('─', $plain);
    }

    #[Test]
    public function mcpIconsMapByConnectionAndAvailability(): void
    {
        $widget = new CompactHeaderWidget($this->theme());
        $widget->setSnapshot(new CompactHeaderSnapshot(
            mcpServers: [
                new McpServerHeaderEntry('global-ok', 1, true, true),
                new McpServerHeaderEntry('specific-ok', 2, true, false),
                new McpServerHeaderEntry('fail', null, false, true),
            ],
        ));

        $plain = $this->plainLines($this->renderWidget($widget, 100));

        $this->assertStringContainsString('✓', $plain);
        $this->assertStringContainsString('◈', $plain);
        $this->assertStringContainsString('✗', $plain);
        $this->assertStringContainsString('global-ok', $plain);
        $this->assertStringContainsString('specific-ok', $plain);
        $this->assertStringContainsString('fail', $plain);
    }

    #[Test]
    public function wrapsAtNarrowWidth(): void
    {
        $widget = new CompactHeaderWidget($this->theme());
        $widget->setSnapshot(new CompactHeaderSnapshot(
            prompts: ['one', 'two', 'three', 'four', 'five', 'six'],
        ));

        $lines = $this->renderWidget($widget, 40);
        $this->assertGreaterThan(1, \count($lines));
    }

    #[Test]
    public function everyRowFitsPathologicalNarrowWidth(): void
    {
        $widget = new CompactHeaderWidget($this->theme());
        $widget->setSnapshot(new CompactHeaderSnapshot(
            prompts: ['one', 'two', 'three', 'four', 'five', 'six'],
            skills: ['alpha', 'beta', 'gamma', 'delta'],
            agentNames: ['scout', 'worker', 'reviewer', 'researcher'],
            mcpServers: [
                new McpServerHeaderEntry('context7', 2, true, true),
                new McpServerHeaderEntry('websearch', 3, true, false),
            ],
        ));

        foreach ([20, 30, 40, 80] as $width) {
            $lines = $this->renderWidget($widget, $width);
            $this->assertGreaterThan(0, \count($lines), "width {$width} must render rows");
            foreach ($lines as $i => $line) {
                $this->assertLessThanOrEqual(
                    $width,
                    AnsiUtils::visibleWidth($line),
                    "row {$i} visible width exceeds {$width}",
                );
            }
        }
    }

    private function theme(): DefaultTheme
    {
        return new DefaultTheme(VirtualTuiHarness::defaultVirtualPalette());
    }

    /** @return string[] */
    private function renderWidget(CompactHeaderWidget $widget, int $width): array
    {
        $root = new ContainerWidget();
        $root->add($widget);

        return (new Renderer())->render($root, $width, 40);
    }

    /** @param list<string> $lines */
    private function plainLines(array $lines): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', implode("\n", $lines)) ?? '';
    }
}
