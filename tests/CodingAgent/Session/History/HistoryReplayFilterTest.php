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
            $this->event(4, 1, RunEventTypeEnum::LlmStepCompleted->value),
            $this->event(5, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2]),
            $this->event(6, 2, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 2]),
            $this->event(7, 2, RunEventTypeEnum::LlmStepCompleted->value),
            $this->event(8, 1, RunEventTypeEnum::HistoryTailDiscarded->value, ['after_turn_no' => 1]),
            $this->event(9, 3, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 3]),
            $this->event(10, 3, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 3]),
            $this->event(11, 3, RunEventTypeEnum::LlmStepCompleted->value),
        ];

        $filtered = $this->filter->filter($events);

        $turnNos = array_values(array_unique(array_map(
            static fn (RunEvent $e): int => $e->turnNo,
            array_filter($filtered, static fn (RunEvent $e): bool => $e->turnNo > 0
                && !\in_array($e->type, [
                    RunEventTypeEnum::HistoryPositionSet->value,
                    RunEventTypeEnum::HistoryTailDiscarded->value,
                ], true)),
        )));
        $this->assertSame([1, 3], $turnNos);
        $this->assertCount(2, array_filter(
            $filtered,
            static fn (RunEvent $event): bool => RunEventTypeEnum::LlmStepCompleted->value === $event->type,
        ));
    }

    #[Test]
    public function testFilterAtPositionKeepsPrefixOnlyIncludingInternalAnchors(): void
    {
        $events = [
            $this->event(1, 0, RunEventTypeEnum::RunStarted->value),
            $this->event(2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            $this->event(3, 1, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 1]),
            $this->event(4, 1, RunEventTypeEnum::LlmStepCompleted->value),
            // Internal retained turn with no human prompt.
            $this->event(5, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2, 'step_id' => 'advance-after-tools']),
            $this->event(6, 2, RunEventTypeEnum::LlmStepCompleted->value),
            $this->event(7, 3, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 3]),
            $this->event(8, 3, RunEventTypeEnum::LlmStepCompleted->value),
        ];

        $filtered = $this->filter->filterAtPosition($events, 2);
        $this->assertSame([1, 2], array_values(array_map(
            static fn (RunEvent $event): int => $event->turnNo,
            array_filter(
                $filtered,
                static fn (RunEvent $event): bool => RunEventTypeEnum::LlmStepCompleted->value === $event->type,
            ),
        )));
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

        $filtered = $this->filter->filterAtPosition($events, 1);
        foreach ($filtered as $event) {
            if (RunEventTypeEnum::AgentCommandApplied->value === $event->type) {
                $this->fail('Seeding command for discarded turn 2 must be excluded from position 1 prefix');
            }
        }
        $this->assertTrue(true);
    }

    /**
     * Thesis: completed position turn → queued/applied follow-up with NO next TurnAdvanced
     * → history_position_set(reason=history_select) must exclude those pending commands.
     */
    #[Test]
    public function testExcludesUnmatchedPendingCommandsAfterCompletionBeforeHistorySelect(): void
    {
        $events = [
            $this->event(1, 0, RunEventTypeEnum::RunStarted->value),
            $this->event(2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            $this->event(3, 1, RunEventTypeEnum::HistoryPositionSet->value, [
                'position_turn_no' => 1,
                'reason' => 'continue',
            ]),
            $this->event(4, 1, RunEventTypeEnum::LlmStepCompleted->value),
            $this->event(5, 1, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']),
            $this->event(6, 1, RunEventTypeEnum::AgentCommandQueued->value, [
                'kind' => 'follow_up',
                'text' => 'pending after complete',
            ]),
            $this->event(7, 1, RunEventTypeEnum::AgentCommandApplied->value, [
                'kind' => 'follow_up',
                'text' => 'pending after complete',
            ]),
            $this->event(8, 1, RunEventTypeEnum::HistoryPositionSet->value, [
                'position_turn_no' => 1,
                'previous_position_turn_no' => 1,
                'reason' => 'history_select',
            ]),
        ];

        $filtered = $this->filter->filterAtPosition($events, 1);

        $commandTypes = [];
        foreach ($filtered as $event) {
            if (\in_array($event->type, [
                RunEventTypeEnum::AgentCommandQueued->value,
                RunEventTypeEnum::AgentCommandApplied->value,
            ], true)) {
                $commandTypes[] = $event->type;
            }
        }
        $this->assertSame([], $commandTypes, 'Unmatched post-completion commands before history_select must be excluded');
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
