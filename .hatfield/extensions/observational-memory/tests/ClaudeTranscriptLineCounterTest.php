<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\HatfieldExt\ObservationalMemory\Claude\ClaudeTranscriptLineCounter;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmTestCase;

final class ClaudeTranscriptLineCounterTest extends OmTestCase
{
    private const string SESSION = '0f8fad5b-d9cb-469f-a165-70867728950e';

    private string $transcript;

    private string $cursor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transcript = $this->directory.'/'.self::SESSION.'.jsonl';
        $this->cursor = $this->directory.'/cursors/'.self::SESSION.'.json';
    }

    public function testCountsCompleteLinesAndResumesAfterTheLastOne(): void
    {
        $counter = new ClaudeTranscriptLineCounter($this->directory.'/cursors', new TestLogger());
        // The unterminated last record is still being written; Librarian ignores it.
        file_put_contents($this->transcript, "{\"a\":1}\n\n{\"partial\"");

        $this->assertSame(2, $counter->completeLines(self::SESSION, $this->transcript));
        $this->assertSame(['inode' => fileinode($this->transcript), 'offset' => 9, 'lines' => 2], $this->storedCursor());

        file_put_contents($this->transcript, ":1}\n{\"c\":3}\n", \FILE_APPEND);
        $this->assertSame(4, $counter->completeLines(self::SESSION, $this->transcript));
        $this->assertSame(['inode' => fileinode($this->transcript), 'offset' => 31, 'lines' => 4], $this->storedCursor());
    }

    public function testReadsOnlyBytesAfterTheCursor(): void
    {
        file_put_contents($this->transcript, "{}\n{}\n");
        mkdir($this->directory.'/cursors');
        // A cursor claiming 100 lines before offset 3 proves the first 3 bytes are not recounted.
        file_put_contents($this->cursor, json_encode(['inode' => fileinode($this->transcript), 'offset' => 3, 'lines' => 100], \JSON_THROW_ON_ERROR));

        $this->assertSame(101, (new ClaudeTranscriptLineCounter($this->directory.'/cursors', new TestLogger()))->completeLines(self::SESSION, $this->transcript));
    }

    public function testRecountsAShrunkenOrReplacedTranscript(): void
    {
        $counter = new ClaudeTranscriptLineCounter($this->directory.'/cursors', new TestLogger());
        file_put_contents($this->transcript, "{}\n{}\n{}\n");
        $this->assertSame(3, $counter->completeLines(self::SESSION, $this->transcript));

        file_put_contents($this->transcript, "{}\n");
        $this->assertSame(1, $counter->completeLines(self::SESSION, $this->transcript));

        // Same size or larger, but a new file: the inode changes.
        file_put_contents($this->directory.'/replacement', "{}\n{}\n");
        rename($this->directory.'/replacement', $this->transcript);
        $this->assertSame(2, $counter->completeLines(self::SESSION, $this->transcript));
    }

    public function testInvalidCursorIsLoggedAndRecounted(): void
    {
        file_put_contents($this->transcript, "{}\n{}\n");
        mkdir($this->directory.'/cursors');
        file_put_contents($this->cursor, '{"inode":"x","offset":-1}');
        $logger = new TestLogger();

        $this->assertSame(2, (new ClaudeTranscriptLineCounter($this->directory.'/cursors', $logger))->completeLines(self::SESSION, $this->transcript));
        $this->assertCount(1, $logger->records);
        $this->assertSame('om.claude.transcript_cursor_invalid', $logger->records[0]['message']);
        $this->assertSame(6, $this->storedCursor()['offset']);
    }

    public function testForgetAndPruneRemoveCursors(): void
    {
        $counter = new ClaudeTranscriptLineCounter($this->directory.'/cursors', new TestLogger());
        file_put_contents($this->transcript, "{}\n");
        $counter->completeLines(self::SESSION, $this->transcript);
        $other = $this->directory.'/cursors/11111111-2222-4333-8444-555555555555.json';
        $stale = $this->directory.'/cursors/22222222-2222-4333-8444-555555555555.json';
        file_put_contents($other, '{}');
        file_put_contents($stale, '{}');
        touch($stale, time() - 7200);

        $counter->forget(self::SESSION);
        $counter->prune(3600);

        $this->assertFileDoesNotExist($this->cursor);
        $this->assertFileDoesNotExist($stale);
        $this->assertFileExists($other);
    }

    /** @return array<string, mixed> */
    private function storedCursor(): array
    {
        return json_decode((string) file_get_contents($this->cursor), true, flags: \JSON_THROW_ON_ERROR);
    }
}
