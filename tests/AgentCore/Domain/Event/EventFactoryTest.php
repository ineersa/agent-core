<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Event;

use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\Builder\RunStateBuilder;
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
            type: 'turn_start',
            payload: ['turn' => 2],
        );

        self::assertSame('run-factory', $event->runId);
        self::assertSame(10, $event->seq);
        self::assertSame(2, $event->turnNo);
        self::assertSame('turn_start', $event->type);
        self::assertSame(['turn' => 2], $event->payload);
    }

    /* ─── EventFactory::eventsFromSpecs() ─── */

    public function testEventsFromSpecsSequencesSeqFromStartSeq(): void
    {
        $factory = new EventFactory();

        $events = $factory->eventsFromSpecs(
            runId: 'run-spec',
            turnNo: 1,
            startSeq: 5,
            eventSpecs: [
                ['type' => 'turn_start', 'payload' => []],
                ['type' => 'message_start', 'payload' => ['role' => 'user']],
            ],
        );

        self::assertCount(2, $events);
        self::assertSame(5, $events[0]->seq);
        self::assertSame(6, $events[1]->seq);
    }

    public function testEventsFromSpecsRespectsTurnNoOverride(): void
    {
        $factory = new EventFactory();

        $events = $factory->eventsFromSpecs(
            runId: 'run-spec',
            turnNo: 1,
            startSeq: 0,
            eventSpecs: [
                ['type' => 'turn_start', 'payload' => [], 'turn_no' => 2],
                ['type' => 'message_start', 'payload' => ['role' => 'user']],
            ],
        );

        self::assertCount(2, $events);
        self::assertSame(2, $events[0]->turnNo);
        self::assertSame(1, $events[1]->turnNo);
    }

    /* ─── EventFactory::incrementStateVersion() ─── */

    public function testIncrementStateVersionOnlyIncrementsVersionAndLastSeq(): void
    {
        $state = RunStateBuilder::running('run-version')
            ->withVersion(5)
            ->withTurnNo(3)
            ->withLastSeq(10)
            ->withIsStreaming(true)
            ->withStreamingMessage(['delta' => 'abc'])
            ->withPendingToolCalls(['call-1' => true])
            ->withErrorMessage('prev error')
            ->withActiveStepId('step-99')
            ->withRetryableFailure(true)
            ->build();

        $factory = new EventFactory();
        $newState = $factory->incrementStateVersion($state, 3);

        self::assertSame('run-version', $newState->runId);
        self::assertSame(RunStatus::Running, $newState->status);
        self::assertSame(6, $newState->version);       // 5 + 1
        self::assertSame(3, $newState->turnNo);          // unchanged
        self::assertSame(13, $newState->lastSeq);        // 10 + 3
        self::assertTrue($newState->isStreaming);         // unchanged
        self::assertSame(['delta' => 'abc'], $newState->streamingMessage);  // unchanged
        self::assertSame(['call-1' => true], $newState->pendingToolCalls);   // unchanged
        self::assertSame('prev error', $newState->errorMessage);             // unchanged
        self::assertSame([], $newState->messages);                           // unchanged (was empty)
        self::assertSame('step-99', $newState->activeStepId);                // unchanged
        self::assertTrue($newState->retryableFailure);                        // unchanged
    }

    public function testIncrementStateVersionWithZeroEventCount(): void
    {
        $state = RunStateBuilder::running('run-version')->withVersion(3)->withLastSeq(7)->build();
        $factory = new EventFactory();
        $newState = $factory->incrementStateVersion($state, 0);

        self::assertSame(4, $newState->version);   // 3 + 1 always
        self::assertSame(7, $newState->lastSeq);    // 7 + 0
    }
}
