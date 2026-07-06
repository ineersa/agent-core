<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test thesis: editor/footer stay on fixed terminal rows while transcript scrolls.
 */
final class TuiBottomLayoutVirtualRenderTest extends TestCase
{
    private const string SESSION_ID = 'bottom-layout-session';

    #[Test]
    public function footerStaysOnLastRowWhenTranscriptExceedsViewport(): void
    {
        $harness = new VirtualTuiHarness(columns: 40, rows: 20, sessionId: self::SESSION_ID);
        $blocks = [];
        for ($i = 1; $i <= 30; ++$i) {
            $blocks[] = new TranscriptBlock(
                id: 'b'.$i,
                kind: TranscriptBlockKindEnum::AssistantMessage,
                runId: self::SESSION_ID,
                seq: $i,
                text: 'assistant chunk '.$i.' '.str_repeat('x', 120),
            );
        }
        $harness->screen()->setTranscriptBlocks($blocks);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();
        $lines = explode("\n", $text);
        $this->assertLessThanOrEqual(20, \count($lines), 'Screen output should not grow past terminal rows');
        $this->assertStringContainsString('session '.self::SESSION_ID, $lines[\count($lines) - 1]);
    }
}
