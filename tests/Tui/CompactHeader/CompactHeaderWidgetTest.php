<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\CompactHeader;

use Ineersa\Tui\CompactHeader\CompactHeaderSnapshot;
use Ineersa\Tui\CompactHeader\CompactHeaderWidget;
use Ineersa\Tui\CompactHeader\McpServerHeaderEntry;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
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
            agentCount: 2,
            agentNames: ['scout', 'worker'],
            mcpServers: [
                new McpServerHeaderEntry('browser', '✓', 3, 'connected'),
            ],
        ));

        $plain = $this->plainLines($widget->render($this->context(120)));

        self::assertStringContainsString('prompts', $plain);
        self::assertStringContainsString('/review', $plain);
        self::assertStringContainsString('skill:castor', $plain);
        self::assertStringContainsString('2 available', $plain);
        self::assertStringContainsString('/agents-live', $plain);
        self::assertStringContainsString('scout', $plain);
        self::assertStringContainsString('browser (3): connected', $plain);
        self::assertStringNotContainsString('─', $plain);
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
