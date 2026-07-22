<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Logging;

use Ineersa\CodingAgent\Logging\LogFilter;
use Ineersa\CodingAgent\Logging\LogParser;
use Ineersa\CodingAgent\Logging\LogReader;
use PHPUnit\Framework\TestCase;

final class LogReaderTest extends TestCase
{
    private string $logDir = '';
    private LogReader $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logDir = sys_get_temp_dir().'/agent-core-log-test-'.getmypid();
        if (is_dir($this->logDir)) {
            $this->rmDir($this->logDir);
        }
        mkdir($this->logDir, 0755, true);

        $this->reader = new LogReader(new LogParser(), $this->logDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ('' !== $this->logDir && is_dir($this->logDir)) {
            $this->rmDir($this->logDir);
        }
    }

    public function testGetLogFilesReturnsEmptyForNonexistentDir(): void
    {
        $reader = new LogReader(new LogParser(), '/does/not/exist');
        $this->assertSame([], $reader->getLogFiles());
    }

    public function testGetLogFilesReturnsEmptyForEmptyDir(): void
    {
        $this->assertSame([], $this->reader->getLogFiles());
    }

    public function testGetLogFilesReturnsSortedByMtime(): void
    {
        $log1 = $this->logDir.'/agent.log';
        $log2 = $this->logDir.'/agent-2026-05-17.log';

        file_put_contents($log1, '');
        touch($log1, time() - 3600); // 1 hour ago

        file_put_contents($log2, '');
        // defaults to now

        $files = $this->reader->getLogFiles();

        $this->assertCount(2, $files);
        $this->assertSame($log2, $files[0]); // newest first
        $this->assertSame($log1, $files[1]);
    }

    public function testReadFilesYieldsParsedEntries(): void
    {
        $lines = [
            json_encode([
                'datetime' => '2026-05-18T10:00:00+00:00',
                'channel' => 'app',
                'level_name' => 'INFO',
                'message' => 'First message',
            ], \JSON_THROW_ON_ERROR),
            json_encode([
                'datetime' => '2026-05-18T10:01:00+00:00',
                'channel' => 'app',
                'level_name' => 'WARNING',
                'message' => 'Second message',
            ], \JSON_THROW_ON_ERROR),
        ];

        file_put_contents($this->logDir.'/agent.log', implode("\n", $lines)."\n");

        $entries = iterator_to_array($this->reader->readFiles([$this->logDir.'/agent.log']));

        $this->assertCount(2, $entries);
        $this->assertSame('First message', $entries[0]->message);
        $this->assertSame('INFO', $entries[0]->level);
        $this->assertSame('Second message', $entries[1]->message);
        $this->assertSame('WARNING', $entries[1]->level);
    }

    public function testReadFilesFiltersByLevel(): void
    {
        $lines = [
            json_encode([
                'datetime' => '2026-05-18T10:00:00+00:00',
                'channel' => 'app',
                'level_name' => 'INFO',
                'message' => 'Info message',
            ], \JSON_THROW_ON_ERROR),
            json_encode([
                'datetime' => '2026-05-18T10:01:00+00:00',
                'channel' => 'app',
                'level_name' => 'ERROR',
                'message' => 'Error message',
            ], \JSON_THROW_ON_ERROR),
        ];

        file_put_contents($this->logDir.'/agent.log', implode("\n", $lines)."\n");

        $filter = new LogFilter(level: 'ERROR');
        $entries = iterator_to_array($this->reader->readFiles([$this->logDir.'/agent.log'], $filter));

        $this->assertCount(1, $entries);
        $this->assertSame('Error message', $entries[0]->message);
    }

    public function testReadFilesRespectsLimit(): void
    {
        $lines = [];
        for ($i = 0; $i < 50; ++$i) {
            $lines[] = json_encode([
                'datetime' => '2026-05-18T10:00:'.\sprintf('%02d', $i).'+00:00',
                'channel' => 'app',
                'level_name' => 'INFO',
                'message' => "Message {$i}",
            ], \JSON_THROW_ON_ERROR);
        }

        file_put_contents($this->logDir.'/agent.log', implode("\n", $lines)."\n");

        $filter = new LogFilter(limit: 10);
        $entries = iterator_to_array($this->reader->readFiles([$this->logDir.'/agent.log'], $filter));

        $this->assertCount(10, $entries);
    }

    public function testReadFilesSkipsInvalidLines(): void
    {
        $lines = [
            'not json',
            json_encode([
                'datetime' => '2026-05-18T10:00:00+00:00',
                'channel' => 'app',
                'level_name' => 'INFO',
                'message' => 'Valid message',
            ], \JSON_THROW_ON_ERROR),
            '',
            '{broken json',
        ];

        file_put_contents($this->logDir.'/agent.log', implode("\n", $lines)."\n");

        $entries = iterator_to_array($this->reader->readFiles([$this->logDir.'/agent.log']));

        $this->assertCount(1, $entries);
        $this->assertSame('Valid message', $entries[0]->message);
    }

    public function testReadFilesHandlesMissingFile(): void
    {
        $entries = iterator_to_array($this->reader->readFiles(['/does/not/exist.log']));
        $this->assertCount(0, $entries);
    }

    public function testTailReturnsEmptyForNoFiles(): void
    {
        $this->assertSame([], $this->reader->tail());
    }

    public function testTailReturnsLastEntries(): void
    {
        $lines = [];
        for ($i = 0; $i < 20; ++$i) {
            $lines[] = json_encode([
                'datetime' => '2026-05-18T10:00:'.\sprintf('%02d', $i).'+00:00',
                'channel' => 'app',
                'level_name' => 'INFO',
                'message' => "Message {$i}",
            ], \JSON_THROW_ON_ERROR);
        }

        file_put_contents($this->logDir.'/agent.log', implode("\n", $lines)."\n");

        $entries = $this->reader->tail(5);

        $this->assertCount(5, $entries);
        // tail returns newest-first, so they should be messages 19..15 reversed
        $this->assertSame('Message 19', $entries[0]->message);
        $this->assertSame('Message 15', $entries[4]->message);
    }

    public function testTailFiltersByLevel(): void
    {
        $lines = [];
        for ($i = 0; $i < 10; ++$i) {
            $lines[] = json_encode([
                'datetime' => '2026-05-18T10:00:'.\sprintf('%02d', $i).'+00:00',
                'channel' => 'app',
                'level_name' => 'INFO',
                'message' => "Info {$i}",
            ], \JSON_THROW_ON_ERROR);
        }
        $lines[] = json_encode([
            'datetime' => '2026-05-18T10:00:11+00:00',
            'channel' => 'app',
            'level_name' => 'ERROR',
            'message' => 'Only error',
        ], \JSON_THROW_ON_ERROR);

        file_put_contents($this->logDir.'/agent.log', implode("\n", $lines)."\n");

        $filter = new LogFilter(level: 'ERROR');
        $entries = $this->reader->tail(50, $filter);

        $this->assertCount(1, $entries);
        $this->assertSame('Only error', $entries[0]->message);
    }

    private function rmDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir((string) $file) : unlink((string) $file);
        }
        rmdir($dir);
    }
}
