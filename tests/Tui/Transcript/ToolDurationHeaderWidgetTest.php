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
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class ToolDurationHeaderWidgetTest extends TestCase
{
    #[DataProvider('durations')]
    public function testSubsecondHeaderDuration(int $milliseconds, string $expected): void
    {
        $widget = new ToolDurationHeaderWidget(
            'read',
            new TranscriptBlock(id: 'result', kind: TranscriptBlockKindEnum::ToolResult, runId: 'run', seq: 1, text: '', meta: ['duration_ms' => $milliseconds]),
            new DefaultTheme(new ThemePalette('test', [])),
            ThemeColorEnum::Dim,
        );
        $this->assertSame('read · '.$expected, AnsiUtils::stripAnsiCodes($widget->getText()));
    }

    public static function durations(): iterable
    {
        yield [0, '0ms'];
        yield [123, '123ms'];
        yield [999, '999ms'];
        yield [1000, '1s'];
        yield [61000, '1m1s'];
    }
}
