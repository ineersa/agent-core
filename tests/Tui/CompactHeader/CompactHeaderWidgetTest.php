<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\CompactHeader;

use Ineersa\Tui\CompactHeader\CompactHeaderSnapshot;
use Ineersa\Tui\CompactHeader\CompactHeaderWidget;
use Ineersa\Tui\CompactHeader\McpServerHeaderEntry;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CompactHeaderWidgetTest extends TestCase
{
    #[Test]
    public function emptySnapshotRendersZeroLines(): void
    {
        $widget = new CompactHeaderWidget();
        $widget->setSnapshot(new CompactHeaderSnapshot());

        self::assertSame([], $widget->render($this->context(80)));
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

        self::assertStringContainsString('prompts', $plain);
        self::assertStringContainsString('│', $plain);
        self::assertStringContainsString('/review', $plain);
        self::assertStringContainsString('skill:castor', $plain);
        self::assertStringContainsString('agents', $plain);
        self::assertStringContainsString('scout', $plain);
        self::assertStringContainsString('worker', $plain);
        self::assertStringNotContainsString('available', $plain);
        self::assertStringNotContainsString('/agents-live', $plain);
        self::assertStringContainsString('context7', $plain);
        self::assertStringContainsString('websearch', $plain);
        self::assertStringContainsString('(2)', $plain);
        self::assertStringContainsString('(3)', $plain);
        self::assertStringContainsString('✓', $plain);
        self::assertStringContainsString('◈', $plain);
        self::assertStringContainsString('✗', $plain);
        self::assertStringNotContainsString(': connected', $plain);
        self::assertStringNotContainsString('─', $plain);
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

        self::assertStringContainsString('✓', $plain);
        self::assertStringContainsString('◈', $plain);
        self::assertStringContainsString('✗', $plain);
        self::assertStringContainsString('global-ok', $plain);
        self::assertStringContainsString('specific-ok', $plain);
        self::assertStringContainsString('fail', $plain);
    }

    #[Test]
    public function wrapsAtNarrowWidth(): void
    {
        $widget = new CompactHeaderWidget();
        $widget->setSnapshot(new CompactHeaderSnapshot(
            prompts: ['one', 'two', 'three', 'four', 'five', 'six'],
        ));

        $lines = $widget->render($this->context(40));
        self::assertGreaterThan(1, \count($lines));
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
