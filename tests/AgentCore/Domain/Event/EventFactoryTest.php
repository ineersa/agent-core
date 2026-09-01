<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Event;

use Ineersa\AgentCore\Domain\Event\EventFactory;
use PHPUnit\Framework\TestCase;

final class EventFactoryTest extends TestCase
{
    /* ─── EventFactory::event() ─── */

    public function testEventFactoryCreatesRunEvent(): void
    {
        $factory = new EventFactory();

        $event = $factory->event(
            runId: 'run-factory',
            seq: 10,
            turnNo: 2,
            type: 'run_started',
            payload: ['turn' => 2],
        );

        $this->assertSame('run-factory', $event->runId);
        $this->assertSame(10, $event->seq);
        $this->assertSame(2, $event->turnNo);
        $this->assertSame('run_started', $event->type);
        $this->assertSame(['turn' => 2], $event->payload);
    }

    public function testEventsFromSpecsSequencesSeqFromStartSeq(): void
    {
        $factory = new EventFactory();

        $events = $factory->eventsFromSpecs(
            runId: 'run-spec',
            turnNo: 1,
            startSeq: 5,
            eventSpecs: [
                ['type' => 'run_started', 'payload' => []],
                ['type' => 'tool_execution_start', 'payload' => ['tool_call_id' => 'call-1']],
            ],
        );

        $this->assertCount(2, $events);
        $this->assertSame(5, $events[0]->seq);
        $this->assertSame(6, $events[1]->seq);
    }

    public function testEventsFromSpecsRespectsTurnNoOverride(): void
    {
        $factory = new EventFactory();

        $events = $factory->eventsFromSpecs(
            runId: 'run-spec',
            turnNo: 1,
            startSeq: 0,
            eventSpecs: [
                ['type' => 'run_started', 'payload' => [], 'turn_no' => 2],
                ['type' => 'tool_execution_start', 'payload' => ['tool_call_id' => 'call-1']],
            ],
        );

        $this->assertCount(2, $events);
        $this->assertSame(2, $events[0]->turnNo);
        $this->assertSame(1, $events[1]->turnNo);
    }
}
