<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\History;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Session\History\HistoryProjector;
use Ineersa\CodingAgent\Session\History\HistoryReplayFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HistoryReplayFilterTest extends TestCase
{
    private HistoryReplayFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new HistoryReplayFilter(new HistoryProjector());
    }

    #[Test]
    public function testFilterExcludesDiscardedContent(): void
    {
        $events = [
            $this->event(1, 0, RunEventTypeEnum::RunStarted->value),
            $this->event(2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            $this->event(3, 1, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 1]),
            $this->event(4, 1, RunEventTypeEnum::LlmStepCompleted->value, ['text' => 'A1']),
            $this->event(5, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2]),
            $this->event(6, 2, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 2]),
            $this->event(7, 2, RunEventTypeEnum::LlmStepCompleted->value, ['text' => 'A2']),
            $this->event(8, 1, RunEventTypeEnum::HistoryTailDiscarded->value, ['after_turn_no' => 1]),
            $this->event(9, 3, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 3]),
            $this->event(10, 3, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 3]),
            $this->event(11, 3, RunEventTypeEnum::LlmStepCompleted->value, ['text' => 'A3']),
        ];

        $result = $this->filter->filter('run-1', $events);
        $this->assertSame([1, 3], $result->retainedTurnNos);
        $this->assertSame(3, $result->positionTurnNo);
        $this->assertSame(11, $result->canonicalEventCount);
        $this->assertSame(11, $result->canonicalLastSeq);

        $turnNos = array_values(array_unique(array_map(
            static fn (RunEvent $e): int => $e->turnNo,
            array_filter($result->events, static fn (RunEvent $e): bool => $e->turnNo > 0
                && !\in_array($e->type, [
                    RunEventTypeEnum::HistoryPositionSet->value,
                    RunEventTypeEnum::HistoryTailDiscarded->value,
                ], true)),
        )));
        $this->assertSame([1, 3], $turnNos);
        $texts = [];
        foreach ($result->events as $event) {
            if (RunEventTypeEnum::LlmStepCompleted->value === $event->type) {
                $texts[] = $event->payload['text'] ?? null;
            }
        }
        $this->assertSame(['A1', 'A3'], $texts);
    }

    #[Test]
    public function testFilterAtPositionKeepsPrefixOnly(): void
    {
        $events = [
            $this->event(1, 0, RunEventTypeEnum::RunStarted->value),
            $this->event(2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            $this->event(3, 1, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 1]),
            $this->event(4, 1, RunEventTypeEnum::LlmStepCompleted->value, ['text' => 'A1']),
            $this->event(5, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2]),
            $this->event(6, 2, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 2]),
            $this->event(7, 2, RunEventTypeEnum::LlmStepCompleted->value, ['text' => 'A2']),
        ];

        $result = $this->filter->filterAtPosition('run-1', $events, 1);
        $this->assertSame([1], $result->retainedTurnNos);
        $this->assertSame(1, $result->positionTurnNo);
        $texts = [];
        foreach ($result->events as $event) {
            if (RunEventTypeEnum::LlmStepCompleted->value === $event->type) {
                $texts[] = $event->payload['text'] ?? null;
            }
        }
        $this->assertSame(['A1'], $texts);
    }

    #[Test]
    public function testExcludesSeedingCommandsForDiscardedCreatedTurn(): void
    {
        $events = [
            $this->event(1, 0, RunEventTypeEnum::RunStarted->value),
            $this->event(2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            $this->event(3, 1, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 1]),
            $this->event(4, 1, RunEventTypeEnum::AgentCommandApplied->value, [
                'kind' => 'follow_up',
                'text' => 'seed next',
            ]),
            $this->event(5, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2]),
            $this->event(6, 2, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 2]),
            $this->event(7, 1, RunEventTypeEnum::HistoryPositionSet->value, [
                'position_turn_no' => 1,
                'reason' => 'history_select',
            ]),
        ];

        $result = $this->filter->filterAtPosition('run-1', $events, 1);
        $this->assertSame([1], $result->retainedTurnNos);
        foreach ($result->events as $event) {
            if (RunEventTypeEnum::AgentCommandApplied->value === $event->type) {
                $this->fail('Seeding command for discarded turn 2 must be excluded from position 1 prefix');
            }
        }
        $this->assertTrue(true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(int $seq, int $turnNo, string $type, array $payload = []): RunEvent
    {
        return new RunEvent(
            runId: 'run-1',
            seq: $seq,
            turnNo: $turnNo,
            type: $type,
            payload: $payload,
            createdAt: new \DateTimeImmutable(),
        );
    }
}
