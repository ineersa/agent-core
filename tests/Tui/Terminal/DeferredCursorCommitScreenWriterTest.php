<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Terminal;

use Ineersa\Tui\Terminal\DeferredCursorCommitScreenWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\CursorShape;
use Symfony\Component\Tui\Terminal\TerminalInterface;

final class DeferredCursorCommitScreenWriterTest extends TestCase
{
    private const string UPSTREAM_SCREEN_WRITER_SHA256 = '5b06f6b76b3d0c53e26327ee0ada88a2666ec46ca8f4eb99e826db227da9c97f';

    #[Test]
    public function copiedWriterTracksTheLockedSymfonyRevision(): void
    {
        $this->assertSame(
            self::UPSTREAM_SCREEN_WRITER_SHA256,
            hash_file('sha256', \dirname(__DIR__, 3).'/vendor/symfony/tui/Render/ScreenWriter.php'),
            'Symfony ScreenWriter changed. Rebase the app-owned copy and update this reviewed hash.',
        );
    }

    #[Test]
    public function itRepeatsCursorCommitOnNextLoopTurnAfterOverheightFrame(): void
    {
        $terminal = $this->terminalExpectingCursorCommits(rows: 3, count: 2);
        $writer = new DeferredCursorCommitScreenWriter($terminal);

        $writer->writeLines([
            'one',
            'two',
            'three',
            'abc'.AnsiUtils::cursorMarker(CursorShape::Bar),
        ]);

        self::runOneLoopTurn();
    }

    #[Test]
    public function itDoesNotRepeatCursorCommitWhenFrameFitsViewport(): void
    {
        $terminal = $this->terminalExpectingCursorCommits(rows: 4, count: 1);
        $writer = new DeferredCursorCommitScreenWriter($terminal);

        $writer->writeLines([
            'one',
            'two',
            'three',
            'abc'.AnsiUtils::cursorMarker(CursorShape::Bar),
        ]);
        self::runOneLoopTurn();
    }

    #[Test]
    public function exportingStateCancelsPendingCursorCommit(): void
    {
        $terminal = $this->terminalExpectingCursorCommits(rows: 3, count: 1);
        $writer = new DeferredCursorCommitScreenWriter($terminal);

        $writer->writeLines([
            'one',
            'two',
            'three',
            'abc'.AnsiUtils::cursorMarker(CursorShape::Bar),
        ]);
        $writer->getState();
        self::runOneLoopTurn();
    }

    private function terminalExpectingCursorCommits(int $rows, int $count): TerminalInterface
    {
        $terminal = $this->createMock(TerminalInterface::class);
        $terminal->method('getColumns')->willReturn(20);
        $terminal->method('getRows')->willReturn($rows);
        $terminal->method('isVirtual')->willReturn(true);
        $terminal->expects($this->exactly($count))->method('showCursor');

        return $terminal;
    }

    private static function runOneLoopTurn(): void
    {
        EventLoop::defer(static function (): void {
        });
        EventLoop::run();
    }
}
