<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Session;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Extension\Session\ExtensionSessionEventReader;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventReaderException;
use PHPUnit\Framework\TestCase;

final class ExtensionSessionEventReaderTest extends TestCase
{
    public function testReadsRequestedRangeWithoutMaterializingAllEvents(): void
    {
        $events = new InMemoryEventStore();
        $events->seed(new RunEvent('run-1', 1, 0, 'run_started', ['ignored' => true], new \DateTimeImmutable('2026-01-01T00:00:00+00:00')));
        $events->seed(new RunEvent('run-1', 3, 1, 'message_end', ['text' => 'kept'], new \DateTimeImmutable('2026-01-01T00:00:01+00:00')));
        $events->seed(new RunEvent('run-1', 5, 2, 'agent_end', ['status' => 'completed'], new \DateTimeImmutable('2026-01-01T00:00:02+00:00')));

        $reader = new ExtensionSessionEventReader($events, new TestLogger());
        $range = iterator_to_array($reader->readRange('run-1', 3, 5));

        $this->assertSame(1, $events->rangeForCalls);
        $this->assertSame(0, $events->allForCalls);
        $this->assertSame([3, 5], array_map(static fn ($event): int => $event->seq, $range));
        $this->assertSame('run-1', $range[0]->runId);
        $this->assertSame(1, $range[0]->turnNo);
        $this->assertSame('message_end', $range[0]->type);
        $this->assertSame(['text' => 'kept'], $range[0]->payload);
        $this->assertSame('2026-01-01T00:00:01+00:00', $range[0]->createdAt);
    }

    public function testMapsRangeReadFailureToPublicException(): void
    {
        $reader = new ExtensionSessionEventReader(new class implements EventStoreInterface {
            public function append(RunEvent $event): RunEvent
            {
                throw new \LogicException('not used');
            }

            public function appendMany(array $events): array
            {
                throw new \LogicException('not used');
            }

            public function latestSequenceFor(string $runId): ?int
            {
                throw new \LogicException('not used');
            }

            public function firstFor(string $runId): ?RunEvent
            {
                throw new \LogicException('not used');
            }

            public function rangeFor(string $runId, int $startSeq, int $endSeq): iterable
            {
                throw new \RuntimeException('store failure');
            }

            public function reverseFor(string $runId): iterable
            {
                return [];
            }

            public function allFor(string $runId): array
            {
                throw new \LogicException('allFor must not be used');
            }
        }, new TestLogger());

        $this->expectException(SessionEventReaderException::class);
        $this->expectExceptionMessage('event store read failed');
        iterator_to_array($reader->readRange('run-1', 1, 1));
    }
}
