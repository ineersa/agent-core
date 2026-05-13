<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Footer;

use Ineersa\Tui\Footer\FooterBarWidget;
use Ineersa\Tui\Footer\FooterDataProvider;
use Ineersa\Tui\Footer\FooterSegment;
use Ineersa\Tui\Footer\FooterSegmentProvider;
use Ineersa\Tui\Footer\ReadonlyFooterDataProvider;
use Ineersa\Tui\Widget\TuiRenderContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FooterDataProvider::class)]
#[CoversClass(FooterBarWidget::class)]
#[CoversClass(FooterSegment::class)]
#[CoversClass(ReadonlyFooterDataProvider::class)]
final class FooterBarWidgetTest extends TestCase
{
    public function testEmptyFooterShowsDefaultText(): void
    {
        $provider = new FooterDataProvider();
        $widget = new FooterBarWidget($provider);
        $context = new TuiRenderContext(terminalWidth: 80);

        $lines = $widget->render($context);

        self::assertCount(1, $lines);
        self::assertStringContainsString('type /help for commands', $lines[0]);
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

        $widget = new FooterBarWidget($provider);
        $context = new TuiRenderContext(terminalWidth: 80);

        $lines = $widget->render($context);

        self::assertCount(1, $lines);
        self::assertStringContainsString('◆ test', $lines[0]);
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

        $widget = new FooterBarWidget($provider);
        $context = new TuiRenderContext(terminalWidth: 80);

        $lines = $widget->render($context);

        self::assertCount(1, $lines);
        self::assertStringContainsString('first · second', $lines[0]);
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

        $widget = new FooterBarWidget($provider);
        $context = new TuiRenderContext(terminalWidth: 80);

        $lines = $widget->render($context);

        self::assertStringContainsString('A · B', $lines[0]);
    }

    public function testStatusEntriesAppearInFooter(): void
    {
        $provider = new FooterDataProvider();
        $provider->setStatus('cost', '$1.23');

        $widget = new FooterBarWidget($provider);
        $context = new TuiRenderContext(terminalWidth: 80);

        $lines = $widget->render($context);

        self::assertStringContainsString('$1.23', $lines[0]);
    }

    public function testReadonlyDataProvider(): void
    {
        $provider = new FooterDataProvider();
        $readonly = $provider->readonly();

        self::assertInstanceOf(ReadonlyFooterDataProvider::class, $readonly);
        self::assertSame([], $readonly->getSegments());
        self::assertSame([], $readonly->getStatusEntries());

        // Add data through original provider
        $provider->setStatus('k', 'v');
        $readonly2 = $provider->readonly();
        self::assertSame(['k' => 'v'], $readonly2->getStatusEntries());
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
        $context = new TuiRenderContext(terminalWidth: 40);
        $widget = new FooterBarWidget($provider);
        $lines = $widget->render($context);

        self::assertCount(1, $lines);
        self::assertStringStartsWith('  ', $lines[0]);
        self::assertLessThanOrEqual(40, \mb_strlen($lines[0]));
    }
}
