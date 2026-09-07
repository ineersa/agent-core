<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Terminal;

use Ineersa\Tui\Terminal\SynchronizedCursorScreenWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\CursorShape;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class SynchronizedCursorScreenWriterTest extends TestCase
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
