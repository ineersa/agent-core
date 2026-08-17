<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Footer;

use Ineersa\Tui\Footer\FooterBarWidget;
use Ineersa\Tui\Footer\FooterDataProvider;
use Ineersa\Tui\Footer\FooterSegment;
use Ineersa\Tui\Footer\FooterSegmentProvider;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Widget\ContainerWidget;

#[CoversClass(FooterDataProvider::class)]
#[CoversClass(FooterBarWidget::class)]
#[CoversClass(FooterSegment::class)]
final class FooterBarWidgetTest extends TestCase
{
    public function testEmptyFooterShowsDefaultText(): void
    {
        $provider = new FooterDataProvider();
        $widget = new FooterBarWidget($this->theme(), $provider);

        $lines = $this->renderWidget($widget, 80);

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('type /help for commands', $lines[0]);
    }

    public function testSingleSegment(): void
    {
        $provider = new FooterDataProvider();
        $provider->addProvider(new class implements FooterSegmentProvider {
            /** @return list<FooterSegment> */
            public function getSegments(): array
            {
                return [new FooterSegment(text: '◆ test', priority: 0)];
            }
        });

        $widget = new FooterBarWidget($this->theme(), $provider);

        $lines = $this->renderWidget($widget, 80);

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('◆ test', $lines[0]);
    }

    public function testSegmentsOrderedByPriority(): void
    {
        $provider = new FooterDataProvider();
        $provider->addProvider(new class implements FooterSegmentProvider {
            /** @return list<FooterSegment> */
            public function getSegments(): array
            {
                return [
                    new FooterSegment(text: 'second', priority: 10),
                    new FooterSegment(text: 'first', priority: 0),
                ];
            }
        });

        $widget = new FooterBarWidget($this->theme(), $provider);

        $lines = $this->renderWidget($widget, 80);

        $this->assertCount(1, $lines);
        // priority gap 10 => "  |  " separator
        $this->assertStringContainsString('first  |  second', $lines[0]);
    }

    public function testMultipleProviders(): void
    {
        $provider = new FooterDataProvider();
        $provider->addProvider(new class implements FooterSegmentProvider {
            /** @return list<FooterSegment> */
            public function getSegments(): array
            {
                return [new FooterSegment(text: 'A', priority: 0)];
            }
        });
        $provider->addProvider(new class implements FooterSegmentProvider {
            /** @return list<FooterSegment> */
            public function getSegments(): array
            {
                return [new FooterSegment(text: 'B', priority: 1)];
            }
        });

        $widget = new FooterBarWidget($this->theme(), $provider);

        $lines = $this->renderWidget($widget, 80);

        // priority gap 1 => space separator
        $this->assertStringContainsString('A B', $lines[0]);
    }

    public function testFooterRespectsTerminalWidth(): void
    {
        $provider = new FooterDataProvider();
        $provider->addProvider(new class implements FooterSegmentProvider {
            /** @return list<FooterSegment> */
            public function getSegments(): array
            {
                return [
                    new FooterSegment(text: 'very long segment text that should be truncated', priority: 0),
                ];
            }
        });

        // Narrow terminal (40 is enough to trigger truncation test without being too small)
        $widget = new FooterBarWidget($this->theme(), $provider);
        $lines = $this->renderWidget($widget, 40);

        $this->assertCount(1, $lines);
        $this->assertStringStartsWith('  ', $lines[0]);
        $this->assertLessThanOrEqual(40, AnsiUtils::visibleWidth($lines[0]));
    }

    public function testSegmentsWrapAcrossLinesAndEveryRowFitsWidth(): void
    {
        $provider = new FooterDataProvider();
        $provider->addProvider(new class implements FooterSegmentProvider {
            /** @return list<FooterSegment> */
            public function getSegments(): array
            {
                return [
                    new FooterSegment(text: 'model: test/model', priority: 100),
                    new FooterSegment(text: 'tokens: 1234', priority: 101),
                    new FooterSegment(text: 'elapsed: 00:01', priority: 102),
                    new FooterSegment(text: 'cwd: /some/very/long/project/path', priority: 110),
                    new FooterSegment(text: 'branch: some-very-long-feature-branch', priority: 111),
                ];
            }
        });

        $widget = new FooterBarWidget($this->theme(), $provider);

        foreach ([30, 50, 80, 120] as $width) {
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
        // Empty palette matches the previous constructor default theme.
        return new DefaultTheme(new ThemePalette('test', []));
    }

    /** @return string[] */
    private function renderWidget(FooterBarWidget $widget, int $width): array
    {
        $root = new ContainerWidget();
        $root->add($widget);

        return (new Renderer())->render($root, $width, 40);
    }
}
