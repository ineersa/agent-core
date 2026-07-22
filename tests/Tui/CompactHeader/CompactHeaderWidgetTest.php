<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\CompactHeader;

use Ineersa\Tui\CompactHeader\CompactHeaderSnapshot;
use Ineersa\Tui\CompactHeader\CompactHeaderWidget;
use Ineersa\Tui\CompactHeader\McpServerHeaderEntry;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Widget\TuiRenderContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CompactHeaderWidgetTest extends TestCase
{
    #[Test]
    public function emptySnapshotRendersZeroLines(): void
    {
        $widget = new CompactHeaderWidget();
        $widget->setSnapshot(new CompactHeaderSnapshot());

        $this->assertSame([], $widget->render($this->context(80)));
    }

    #[Test]
    public function rendersPromptsSkillsAgentsAndMcpSections(): void
    {
        $widget = new CompactHeaderWidget();
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

        $plain = $this->plainLines($widget->render($this->context(120)));

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
        $widget = new CompactHeaderWidget();
        $widget->setSnapshot(new CompactHeaderSnapshot(
            mcpServers: [
                new McpServerHeaderEntry('global-ok', 1, true, true),
                new McpServerHeaderEntry('specific-ok', 2, true, false),
                new McpServerHeaderEntry('fail', null, false, true),
            ],
        ));

        $plain = $this->plainLines($widget->render($this->context(100)));

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
        $widget = new CompactHeaderWidget();
        $widget->setSnapshot(new CompactHeaderSnapshot(
            prompts: ['one', 'two', 'three', 'four', 'five', 'six'],
        ));

        $lines = $widget->render($this->context(40));
        $this->assertGreaterThan(1, \count($lines));
    }

    private function context(int $width): TuiRenderContext
    {
        return new TuiRenderContext(
            terminalWidth: $width,
            terminalHeight: 24,
            theme: new DefaultTheme(VirtualTuiHarness::defaultVirtualPalette()),
        );
    }

    /** @param list<string> $lines */
    private function plainLines(array $lines): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', implode("\n", $lines)) ?? '';
    }
}
