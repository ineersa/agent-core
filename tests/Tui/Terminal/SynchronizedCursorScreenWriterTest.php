<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Terminal;

use Ineersa\Tui\Terminal\SynchronizedCursorScreenWriter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver;
use Revolt\EventLoop\DriverFactory;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\ArrayLineBuffer;
use Symfony\Component\Tui\Style\CursorShape;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

#[AllowMockObjectsWithoutExpectations]
final class SynchronizedCursorScreenWriterTest extends TestCase
{
    private const string UPSTREAM_SCREEN_WRITER_SHA256 = '05f85e00e3ab414d82af6245c89befa52bef12b310bbc47123e5fa767c1ab5cb';

    private Driver $previousDriver;

    protected function setUp(): void
    {
        $this->previousDriver = EventLoop::getDriver();
        // A ParaTest worker may already have TUI timers. Run only this test's callbacks.
        EventLoop::setDriver((new DriverFactory())->create());
    }

    protected function tearDown(): void
    {
        EventLoop::setDriver($this->previousDriver);
    }

    #[Test]
    #[DataProvider('deferredFrames')]
    public function itCommitsOnlyTheLatestEligibleCursorOnTheNextTurn(string $action, string $expected): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 3);
        $writer = new SynchronizedCursorScreenWriter($terminal);
        $lines = ['one', 'two', 'three', 'abc'.AnsiUtils::cursorMarker(CursorShape::Bar)];
        $writer->writeFrame($this->frame($lines));

        match ($action) {
            'reset' => $writer->reset(),
            'shutdown' => $writer->getState(),
            'short' => $writer->writeFrame($this->frame([$lines[3]])),
            'unfocused' => $writer->writeFrame($this->frame(['one', 'two', 'three', 'abc'])),
            'new cursor' => $writer->writeFrame($this->frame(['one', 'two', 'three', 'abcd'.AnsiUtils::cursorMarker(CursorShape::Bar)])),
            default => null,
        };
        $terminal->clearOutput();

        // Keep the loop alive for one turn without timers or elapsed-time waits.
        EventLoop::defer(static function (): void {});
        EventLoop::run();

        $this->assertSame($expected, $terminal->getOutput());
    }

    /** @return iterable<string, array{string, string}> */
    public static function deferredFrames(): iterable
    {
        yield 'overheight cursor only' => ['none', "\x1b[4G\x1b[5 q\x1b[?25h"];
        yield 'latest cursor wins' => ['new cursor', "\x1b[5G\x1b[5 q\x1b[?25h"];
        yield 'reset cancels' => ['reset', ''];
        yield 'shutdown cancels' => ['shutdown', ''];
        yield 'short frame cancels' => ['short', ''];
        yield 'focus loss cancels' => ['unfocused', ''];
    }

    #[Test]
    public function copiedWriterTracksTheLockedSymfonyRevision(): void
    {
        $this->assertSame(
            self::UPSTREAM_SCREEN_WRITER_SHA256,
            hash_file('sha256', \dirname(__DIR__, 3).'/vendor/symfony/tui/Render/ScreenWriter.php'),
            'Symfony ScreenWriter changed. Rebase the app-owned copy and update this reviewed hash.',
        );
    }

    /**
     * @param list<string> $before
     * @param list<string> $after
     */
    #[Test]
    #[DataProvider('repaintFrames')]
    public function itRestoresTheCursorBeforeReleasingTheFrame(array $before, array $after, string $rowMovement): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 3);
        $writer = new SynchronizedCursorScreenWriter($terminal);
        if ([] !== $before) {
            $writer->writeFrame($this->frame($before));
            $terminal->clearOutput();
        }

        $writer->writeFrame($this->frame($after));

        $output = $terminal->getOutput();
        $this->assertStringStartsWith("\x1b[?2026h\x1b[?25l", $output);
        $this->assertStringEndsWith($rowMovement."\x1b[4G\x1b[5 q\x1b[?25h\x1b[?2026l", $output);
        $this->assertSame(1, substr_count($output, "\x1b[?2026l"), 'No frame may be released before cursor restoration.');
    }

    /** @return iterable<string, array{list<string>, list<string>, string}> */
    public static function repaintFrames(): iterable
    {
        $editor = 'abc'.AnsiUtils::cursorMarker(CursorShape::Bar);

        yield 'full' => [[], ['one', $editor, 'footer'], "\x1b[1A"];
        yield 'stream delta' => [['one', $editor, 'footer'], ['two', $editor, 'footer'], "\x1b[1B"];
        yield 'trailing deletion' => [['one', $editor, 'footer', 'tail'], ['one', $editor, 'footer'], "\x1b[1A"];
        yield 'overheight full' => [[], ['one', 'two', 'three', 'stream', $editor, 'footer'], "\x1b[1A"];
        yield 'overheight stream delta' => [
            ['one', 'two', 'three', 'stream', $editor, 'footer'],
            ['one', 'two', 'three', 'streaming', $editor, 'footer'],
            "\x1b[1B",
        ];
    }

    #[Test]
    public function itDifferentiallyShrinksTrailingOverlayRowsOnPhysicalTerminals(): void
    {
        // Mirrors #477 picker close: overheight shrink with an intact transcript prefix
        // must not clear+replay via redrawViewport.
        $output = new VirtualTerminal(columns: 20, rows: 3);
        $terminal = $this->createStub(TerminalInterface::class);
        $terminal->method('getColumns')->willReturn(20);
        $terminal->method('getRows')->willReturn(3);
        $terminal->method('isVirtual')->willReturn(false);
        $terminal->method('write')->willReturnCallback($output->write(...));
        $terminal->method('showCursor')->willReturnCallback($output->showCursor(...));
        $terminal->method('hideCursor')->willReturnCallback($output->hideCursor(...));

        $writer = new SynchronizedCursorScreenWriter($terminal);
        $editor = 'abc'.AnsiUtils::cursorMarker(CursorShape::Bar);
        $writer->writeFrame($this->frame(['one', 'two', $editor, 'overlay-a', 'overlay-b']));
        $output->clearOutput();

        $writer->writeFrame($this->frame(['one', 'two', $editor]));

        $delta = $output->getOutput();
        $this->assertStringNotContainsString("\x1b[2J", $delta);
        $this->assertStringNotContainsString('one', $delta);
        $this->assertStringNotContainsString('two', $delta);
        $this->assertStringStartsWith("\x1b[?2026h\x1b[?25l", $delta);
        $this->assertStringEndsWith("\x1b[?2026l", $delta);
    }

    #[Test]
    public function itKeepsTheCursorHiddenWhenTheEditorLosesFocus(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 3);
        $writer = new SynchronizedCursorScreenWriter($terminal);
        $writer->writeFrame($this->frame(['one', 'abc'.AnsiUtils::cursorMarker(CursorShape::Bar), 'footer']));
        $terminal->clearOutput();

        $writer->writeFrame($this->frame(['two', 'abc', 'footer']));

        $output = $terminal->getOutput();
        $this->assertStringStartsWith("\x1b[?2026h\x1b[?25l", $output);
        $this->assertStringEndsWith("\x1b[?25l\x1b[?2026l", $output);
        $this->assertStringNotContainsString("\x1b[?25h", $output);
    }

    /** @param list<string> $lines */
    private function frame(array $lines): ArrayLineBuffer
    {
        return new ArrayLineBuffer($lines);
    }
}
