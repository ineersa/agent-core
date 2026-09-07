<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Terminal;

use Ineersa\Tui\Terminal\SynchronizedCursorScreenWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver;
use Revolt\EventLoop\DriverFactory;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\CursorShape;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class SynchronizedCursorScreenWriterTest extends TestCase
{
    private const string UPSTREAM_SCREEN_WRITER_SHA256 = '5b06f6b76b3d0c53e26327ee0ada88a2666ec46ca8f4eb99e826db227da9c97f';

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
        $writer->writeLines($lines);

        match ($action) {
            'reset' => $writer->reset(),
            'shutdown' => $writer->getState(),
            'short' => $writer->writeLines([$lines[3]]),
            'unfocused' => $writer->writeLines(['one', 'two', 'three', 'abc']),
            'new cursor' => $writer->writeLines(['one', 'two', 'three', 'abcd'.AnsiUtils::cursorMarker(CursorShape::Bar)]),
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
            $writer->writeLines($before);
            $terminal->clearOutput();
        }

        $writer->writeLines($after);

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
    public function itKeepsTheCursorHiddenWhenTheEditorLosesFocus(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 3);
        $writer = new SynchronizedCursorScreenWriter($terminal);
        $writer->writeLines(['one', 'abc'.AnsiUtils::cursorMarker(CursorShape::Bar), 'footer']);
        $terminal->clearOutput();

        $writer->writeLines(['two', 'abc', 'footer']);

        $output = $terminal->getOutput();
        $this->assertStringStartsWith("\x1b[?2026h\x1b[?25l", $output);
        $this->assertStringEndsWith("\x1b[?25l\x1b[?2026l", $output);
        $this->assertStringNotContainsString("\x1b[?25h", $output);
    }
}
