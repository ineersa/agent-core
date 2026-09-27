<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\ToolDurationHeaderWidget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class ToolDurationHeaderWidgetTest extends TestCase
{
    #[DataProvider('durations')]
    public function testHeaderDuration(int $milliseconds, bool $streaming, string $expected): void
    {
        $start = new \DateTimeImmutable('2026-09-25T12:00:00+00:00');
        $widget = new ToolDurationHeaderWidget(
            'read',
            new TranscriptBlock(
                id: 'result', kind: TranscriptBlockKindEnum::ToolResult, runId: 'run', seq: 1, text: '',
                meta: ['duration_ms' => $milliseconds, 'started_at' => $start->format(\DATE_ATOM)],
                streaming: $streaming,
            ),
            new DefaultTheme(new ThemePalette('test', [])),
            ThemeColorEnum::Dim,
            clock: new MockClock($start->modify($milliseconds.' milliseconds')),
        );
        $this->assertSame('read · '.$expected, AnsiUtils::stripAnsiCodes($widget->getText()));
    }

    public static function durations(): iterable
    {
        yield 'completed immediately' => [0, false, '0ms'];
        yield 'completed subsecond' => [123, false, '123ms'];
        yield 'completed below one second' => [999, false, '999ms'];
        yield 'completed one second' => [1000, false, '1s'];
        yield 'completed minute' => [61000, false, '1m1s'];
        yield 'live immediately' => [0, true, '0s'];
        yield 'live subsecond' => [123, true, '0s'];
        yield 'live below one second' => [999, true, '0s'];
        yield 'live one second' => [1000, true, '1s'];
        yield 'live minute' => [61000, true, '1m1s'];
    }
}
