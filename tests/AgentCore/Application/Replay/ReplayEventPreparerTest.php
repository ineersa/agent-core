<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Replay;

use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use PHPUnit\Framework\TestCase;

final class ReplayEventPreparerTest extends TestCase
{
    public function testDuplicateSequencesReturnsUniqueDuplicateSeqs(): void
    {
        $preparer = new ReplayEventPreparer();
        $events = [
            $this->event(1),
            $this->event(2),
            $this->event(2),
            $this->event(2),
            $this->event(4),
        ];

        $this->assertSame([2], $preparer->duplicateSequences($events));
    }

    public function testMissingSequencesDetectsGaps(): void
    {
        $preparer = new ReplayEventPreparer();
        $events = [
            $this->event(1),
            $this->event(3),
            $this->event(5),
        ];

        $this->assertSame([2, 4], $preparer->missingSequences($events));
    }

    private function event(int $seq): RunEvent
    {
        return new RunEvent(
            runId: 'run-prep',
            seq: $seq,
            turnNo: 0,
            type: RunEventTypeEnum::TurnEnd->value,
            payload: [],
            createdAt: new \DateTimeImmutable(),
        );
    }
}
