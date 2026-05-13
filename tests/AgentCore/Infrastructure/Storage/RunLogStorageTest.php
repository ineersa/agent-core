<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\Storage;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Infrastructure\Storage\RunLogReader;
use Ineersa\AgentCore\Infrastructure\Storage\RunLogWriter;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;

final class RunLogStorageTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir().'/agent-core-run-log-'.bin2hex(random_bytes(8));
        mkdir($this->basePath, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);
    }

    public function testWriterAndReaderRoundTripAcrossMonthlyPartitions(): void
    {
        $filesystem = new Filesystem(new LocalFilesystemAdapter($this->basePath));
        $writer = new RunLogWriter($filesystem);
        $reader = new RunLogReader($filesystem);

        $runId = 'run-42';

        $writer->append(new RunEvent(
            runId: $runId,
            seq: 2,
            turnNo: 1,
            type: 'assistant_message',
            payload: ['assistant' => 'Hello from February'],
            createdAt: new \DateTimeImmutable('2026-02-01T10:00:00+00:00'),
        ));

        $writer->append(new RunEvent(
            runId: $runId,
            seq: 1,
            turnNo: 0,
            type: 'run_started',
            payload: ['messages' => []],
            createdAt: new \DateTimeImmutable('2026-01-30T10:00:00+00:00'),
        ));

        try {
            $februaryLog = $filesystem->read('2026/02/'.$runId.'.jsonl');
        } catch (\Throwable $exception) {
            self::fail('Failed to read February run log: '.$exception->getMessage());
        }

        self::assertStringContainsString('"schema_version":"1.0"', $februaryLog);

        $events = $reader->allFor($runId);

        self::assertCount(2, $events);
        self::assertSame([1, 2], array_map(static fn (RunEvent $event): int => $event->seq, $events));
        self::assertSame('run_started', $events[0]->type);
        self::assertSame('assistant_message', $events[1]->type);
    }

    public function testReaderSkipsMalformedLines(): void
    {
        $filesystem = new Filesystem(new LocalFilesystemAdapter($this->basePath));
        $reader = new RunLogReader($filesystem);

        $runId = 'run-malformed';

        $filesystem->write('2026/04/'.$runId.'.jsonl', <<<JSONL
not-a-json-line
{"seq":1,"ts":"2026-04-12T12:00:00+00:00","run_id":"run-malformed","turn_no":0,"type":"run_started","payload":{"messages":[]}}
{"seq":"wrong","run_id":"run-malformed","turn_no":0,"type":"invalid","payload":[]}
JSONL
        );

        $events = $reader->allFor($runId);

        self::assertCount(1, $events);
        self::assertSame(1, $events[0]->seq);
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());

                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }
}
