<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Session;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Extension\Session\ExtensionSessionEventReader;
use Ineersa\CodingAgent\Session\SessionExistenceCheckerInterface;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventReaderException;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: inclusive canonical reads return immutable public DTOs with exact
 * (run_id, seq)/payload/type/time and distinguish missing sessions.
 */
final class ExtensionSessionEventReaderTest extends TestCase
{
    public function testReadRangeReturnsInclusivePublicDtos(): void
    {
        $eventStore = new InMemoryEventStore();
        $createdAt = new \DateTimeImmutable('2026-07-21T12:00:00+00:00');
        $eventStore->append(new RunEvent('run-a', 1, 1, 'run_started', ['k' => 1], $createdAt));
        $eventStore->append(new RunEvent('run-a', 2, 1, 'llm_step_completed', ['k' => 2], $createdAt));
        $eventStore->append(new RunEvent('run-a', 3, 1, 'agent_end', ['reason' => 'completed'], $createdAt));

        $sessions = $this->createMock(SessionExistenceCheckerInterface::class);
        $sessions->expects($this->once())->method('exists')->with('run-a')->willReturn(true);

        $reader = new ExtensionSessionEventReader($eventStore, $sessions, new TestLogger());
        $events = $reader->readRange('run-a', 2, 3);

        $this->assertCount(2, $events);
        $this->assertSame('run-a', $events[0]->runId);
        $this->assertSame(2, $events[0]->seq);
        $this->assertSame('llm_step_completed', $events[0]->type);
        $this->assertSame(['k' => 2], $events[0]->payload);
        $this->assertEquals($createdAt, $events[0]->createdAt);
        $this->assertSame(3, $events[1]->seq);
        $this->assertSame('agent_end', $events[1]->type);
    }

    public function testMissingSessionUsesStablePublicError(): void
    {
        $sessions = $this->createMock(SessionExistenceCheckerInterface::class);
        $sessions->expects($this->once())->method('exists')->with('missing')->willReturn(false);

        $reader = new ExtensionSessionEventReader(new InMemoryEventStore(), $sessions, new TestLogger());

        try {
            $reader->readRange('missing', 1, 2);
            $this->fail('Expected SessionEventReaderException');
        } catch (SessionEventReaderException $e) {
            $this->assertSame(SessionEventReaderException::CODE_MISSING_SESSION, $e->errorCode);
        }
    }

    public function testExistingSessionWithNoEventsReturnsEmptyList(): void
    {
        $sessions = $this->createMock(SessionExistenceCheckerInterface::class);
        $sessions->expects($this->once())->method('exists')->with('empty-run')->willReturn(true);

        $reader = new ExtensionSessionEventReader(new InMemoryEventStore(), $sessions, new TestLogger());
        $this->assertSame([], $reader->readRange('empty-run', 1, 10));
    }

    public function testInvalidRangeRejected(): void
    {
        $sessions = $this->createStub(SessionExistenceCheckerInterface::class);
        $reader = new ExtensionSessionEventReader(new InMemoryEventStore(), $sessions, new TestLogger());

        $this->expectException(SessionEventReaderException::class);
        $this->expectExceptionMessage('Invalid inclusive event range');
        $reader->readRange('run-x', 5, 2);
    }
}
