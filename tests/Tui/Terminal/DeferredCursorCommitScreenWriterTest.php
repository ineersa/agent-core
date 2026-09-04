<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Terminal;

use Ineersa\Tui\Terminal\DeferredCursorCommitScreenWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\CursorShape;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class DeferredCursorCommitScreenWriterTest extends TestCase
{
    #[Test]
    public function itRepeatsCursorCommitOnNextLoopTurnAfterOverheightFrame(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 3);
        $writer = new DeferredCursorCommitScreenWriter($terminal);
        $cursorCommit = "\x1b[4G\x1b[5 q\x1b[?25h";

        $writer->writeLines([
            'one',
            'two',
            'three',
            'abc'.AnsiUtils::cursorMarker(CursorShape::Bar),
        ]);

        $this->assertSame(1, substr_count($terminal->getOutput(), $cursorCommit));

        self::runOneLoopTurn();

        $this->assertSame(2, substr_count($terminal->getOutput(), $cursorCommit));
    }

    #[Test]
    public function itDoesNotRepeatCursorCommitWhenFrameFitsViewport(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 4);
        $writer = new DeferredCursorCommitScreenWriter($terminal);
        $cursorCommit = "\x1b[4G\x1b[5 q\x1b[?25h";

        $writer->writeLines([
            'one',
            'two',
            'three',
            'abc'.AnsiUtils::cursorMarker(CursorShape::Bar),
        ]);
        self::runOneLoopTurn();

        $this->assertSame(1, substr_count($terminal->getOutput(), $cursorCommit));
    }

    private static function runOneLoopTurn(): void
    {
        EventLoop::defer(static function (): void {
        });
        EventLoop::run();
    }
}
