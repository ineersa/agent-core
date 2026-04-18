<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\JsonlOutboxProjectorWorker;
use Ineersa\AgentCore\Application\Handler\MercureOutboxProjectorWorker;
use Ineersa\AgentCore\Application\Handler\OutboxProjector;
use Ineersa\AgentCore\Domain\Event\OutboxSink;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\ProjectJsonlOutbox;
use Ineersa\AgentCore\Domain\Message\ProjectMercureOutbox;
use Ineersa\AgentCore\Infrastructure\Mercure\RunEventPublisher;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryOutboxStore;
use Ineersa\AgentCore\Infrastructure\Storage\RunLogReader;
use Ineersa\AgentCore\Infrastructure\Storage\RunLogWriter;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;

final class OutboxProjectionWorkerTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir().'/agent-core-outbox-'.uniqid('', true);
        mkdir($this->basePath, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);
    }

    public function testProjectorEnqueuesPerSinkAndWorkersDrainOutboxIdempotently(): void
    {
        $filesystem = new Filesystem(new LocalFilesystemAdapter($this->basePath));

        $outboxStore = new InMemoryOutboxStore();
        $runLogWriter = new RunLogWriter($filesystem);
        $runEventPublisher = new RunEventPublisher();

        $projector = new OutboxProjector($outboxStore, $runLogWriter, $runEventPublisher);
        $jsonlWorker = new JsonlOutboxProjectorWorker($outboxStore, $runLogWriter);
        $mercureWorker = new MercureOutboxProjectorWorker($outboxStore, $runEventPublisher);

        $event = new RunEvent(
            runId: 'run-outbox-1',
            seq: 1,
            turnNo: 0,
            type: 'agent_command_rejected',
            payload: ['kind' => 'ext:unknown'],
            createdAt: new \DateTimeImmutable('2026-04-12T12:00:00+00:00'),
        );

        $projector->project([$event]);
        $projector->project([$event]);

        $jsonlWorker->__invoke(new ProjectJsonlOutbox());
        $mercureWorker->__invoke(new ProjectMercureOutbox());

        $reader = new RunLogReader($filesystem);
        $events = $reader->allFor('run-outbox-1');

        self::assertCount(1, $events, 'Duplicate outbox enqueue should not duplicate projected JSONL event.');
        self::assertSame('agent_command_rejected', $events[0]->type);

        self::assertSame([], $outboxStore->claim(OutboxSink::Jsonl));
        self::assertSame([], $outboxStore->claim(OutboxSink::Mercure));

        // Re-running workers should be a no-op.
        $jsonlWorker->__invoke(new ProjectJsonlOutbox());
        $mercureWorker->__invoke(new ProjectMercureOutbox());

        self::assertCount(1, $reader->allFor('run-outbox-1'));
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
