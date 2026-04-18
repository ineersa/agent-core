<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ReplayService;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Infrastructure\Storage\HotPromptStateStore;
use Ineersa\AgentCore\Infrastructure\Storage\RunEventStore;
use Ineersa\AgentCore\Infrastructure\Storage\RunLogReader;
use Ineersa\AgentCore\Infrastructure\Storage\RunLogWriter;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;

final class ReplayServiceTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir().'/agent-core-replay-'.uniqid('', true);
        mkdir($this->basePath, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);
    }

    public function testRebuildUsesCanonicalEventsAndRestoresDeletedHotPromptState(): void
    {
        $filesystem = new Filesystem(new LocalFilesystemAdapter($this->basePath));
        $eventStore = new RunEventStore();
        $hotPromptStore = new HotPromptStateStore();
        $replayService = new ReplayService($eventStore, new RunLogReader($filesystem), $hotPromptStore);

        $runId = 'run-replay-canonical';
        $eventStore->append(new RunEvent(
            runId: $runId,
            seq: 1,
            turnNo: 0,
            type: 'run_started',
            payload: [
                'messages' => [[
                    'role' => 'user',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'Hello',
                    ]],
                ]],
            ],
            createdAt: new \DateTimeImmutable('2026-04-12T12:00:00+00:00'),
        ));
        $eventStore->append(new RunEvent(
            runId: $runId,
            seq: 2,
            turnNo: 1,
            type: 'assistant_message',
            payload: [
                'message' => [
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'Hi!',
                    ]],
                ],
            ],
            createdAt: new \DateTimeImmutable('2026-04-12T12:01:00+00:00'),
        ));

        $rebuiltState = $replayService->rebuildHotPromptState($runId);

        self::assertSame('canonical_events', $rebuiltState['source']);
        self::assertSame(2, $rebuiltState['last_seq']);
        self::assertCount(2, $rebuiltState['messages']);
        self::assertNotNull($hotPromptStore->get($runId));

        $hotPromptStore->delete($runId);
        self::assertNull($hotPromptStore->get($runId));

        $rebuiltAfterDelete = $replayService->rebuildHotPromptState($runId);

        self::assertSame($rebuiltState['messages'], $rebuiltAfterDelete['messages']);
        self::assertNotNull($hotPromptStore->get($runId));

        $integrity = $replayService->verifyIntegrity($runId);
        self::assertTrue($integrity['is_contiguous']);
        self::assertSame([], $integrity['missing_sequences']);
    }

    public function testRebuildFallsBackToJsonlWhenCanonicalEventsAreUnavailable(): void
    {
        $filesystem = new Filesystem(new LocalFilesystemAdapter($this->basePath));
        $writer = new RunLogWriter($filesystem);

        $runId = 'run-replay-jsonl';
        $writer->append(new RunEvent(
            runId: $runId,
            seq: 1,
            turnNo: 0,
            type: 'run_started',
            payload: ['messages' => []],
            createdAt: new \DateTimeImmutable('2026-04-10T12:00:00+00:00'),
        ));
        $writer->append(new RunEvent(
            runId: $runId,
            seq: 3,
            turnNo: 1,
            type: 'assistant_message',
            payload: ['assistant' => 'Recovered from JSONL'],
            createdAt: new \DateTimeImmutable('2026-04-10T12:01:00+00:00'),
        ));

        $eventStore = new RunEventStore();
        $hotPromptStore = new HotPromptStateStore();
        $replayService = new ReplayService($eventStore, new RunLogReader($filesystem), $hotPromptStore);

        $rebuiltState = $replayService->rebuildHotPromptState($runId);

        self::assertSame('jsonl_fallback', $rebuiltState['source']);
        self::assertSame([2], $rebuiltState['missing_sequences']);
        self::assertFalse($rebuiltState['is_contiguous']);
        self::assertSame(3, $rebuiltState['last_seq']);
        self::assertCount(1, $rebuiltState['messages']);
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
