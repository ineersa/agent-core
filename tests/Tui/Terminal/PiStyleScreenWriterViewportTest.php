<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Terminal;

use Ineersa\Tui\Terminal\PiStyleScreenWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic physical-viewport proof for Pi-style ScreenWriter bookkeeping.
 *
 * Uses a finite non-virtual terminal emulator so over-height native-scroll
 * updates exercise logical→physical row targeting without a live TTY.
 */
#[CoversClass(PiStyleScreenWriter::class)]
final class PiStyleScreenWriterViewportTest extends TestCase
{
    public function testOverHeightBottomChromeUpdateDoesNotLeaveStaleDuplicatedRows(): void
    {
        $columns = 20;
        $height = 6;
        $terminal = new PhysicalViewportTerminal($columns, $height);
        $writer = new PiStyleScreenWriter($terminal);

        $frame1 = [
            'T0------------------',
            'T1------------------',
            'T2------------------',
            'SEP=================',
            'ED------------------',
            'FOOTER--------------',
        ];
        $writer->writeLines($frame1);

        $this->assertSame($frame1, $this->trimVisible($terminal->visibleRows()));

        // Grow above the physical viewport while changing only the bottom chrome.
        // Without viewport-aware targeting, CUU/CUD from a logical hardware row
        // paints into the wrong physical row and leaves a stale chrome line.
        $frame2 = [
            'T0------------------',
            'T1------------------',
            'T2------------------',
            'T3------------------',
            'T4------------------',
            'SEP=================',
            'ED-UPDATED----------',
            'FOOTER-UPDATED------',
        ];
        $writer->writeLines($frame2);

        $visible = $this->trimVisible($terminal->visibleRows());
        $this->assertSame([
            'T2------------------',
            'T3------------------',
            'T4------------------',
            'SEP=================',
            'ED-UPDATED----------',
            'FOOTER-UPDATED------',
        ], $visible);
        $this->assertSame(1, $this->countExact($visible, 'SEP================='));
        $this->assertSame(1, $this->countExact($visible, 'ED-UPDATED----------'));
        $this->assertSame(1, $this->countExact($visible, 'FOOTER-UPDATED------'));
        $this->assertSame(0, $this->countExact($visible, 'ED------------------'));
        $this->assertSame(0, $this->countExact($visible, 'FOOTER--------------'));

        $state = $writer->getState();
        $this->assertSame(8, $state['line_count']);
        $this->assertSame(7, $state['cursor_row']);
    }

    public function testAppendBeyondViewportAdvancesVisibleBottomWithoutDuplicatingFooter(): void
    {
        $columns = 16;
        $height = 5;
        $terminal = new PhysicalViewportTerminal($columns, $height);
        $writer = new PiStyleScreenWriter($terminal);

        $writer->writeLines([
            'A---------------',
            'B---------------',
            'C---------------',
            'EDITOR----------',
            'FOOTER----------',
        ]);

        $writer->writeLines([
            'A---------------',
            'B---------------',
            'C---------------',
            'D---------------',
            'E---------------',
            'EDITOR-NEW------',
            'FOOTER-NEW------',
        ]);

        $visible = $this->trimVisible($terminal->visibleRows());
        $this->assertSame([
            'C---------------',
            'D---------------',
            'E---------------',
            'EDITOR-NEW------',
            'FOOTER-NEW------',
        ], $visible);
        $this->assertSame(1, $this->countExact($visible, 'FOOTER-NEW------'));
        $this->assertSame(0, $this->countExact($visible, 'FOOTER----------'));
    }

    /**
     * @param list<string> $rows
     *
     * @return list<string>
     */
    private function trimVisible(array $rows): array
    {
        return array_map(static fn (string $row): string => rtrim($row), $rows);
    }

    /**
     * @param list<string> $rows
     */
    private function countExact(array $rows, string $needle): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if ($row === $needle) {
                ++$count;
            }
        }

        return $count;
    }
}
