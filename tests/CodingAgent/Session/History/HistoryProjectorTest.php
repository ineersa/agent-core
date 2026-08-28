<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\History;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Session\History\HistoryProjector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HistoryProjectorTest extends TestCase
{
    private HistoryProjector $projector;

    protected function setUp(): void
    {
        $this->projector = new HistoryProjector();
    }

    #[Test]
    public function testEmptyStream(): void
    {
        $history = $this->projector->build([]);
        $this->assertSame([], $history->retainedTurnNos);
        $this->assertSame([], $history->promptsByTurnNo);
        $this->assertSame(0, $history->positionTurnNo);
    }

    #[Test]
    public function testInitialAndFollowUpHumanPromptsMapToAnchors(): void
    {
        $events = [
            $this->event(1, 0, RunEventTypeEnum::RunStarted->value, [
                'payload' => ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello']]]]],
            ]),
            $this->event(2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            $this->event(3, 1, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 1, 'reason' => 'continue']),
            $this->event(4, 1, RunEventTypeEnum::AgentCommandApplied->value, [
                'kind' => 'follow_up',
                'text' => 'Write a test.',
            ]),
            $this->event(5, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2]),
            $this->event(6, 2, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 2, 'reason' => 'continue']),
        ];

        $history = $this->projector->build($events);
        $this->assertSame([1, 2], $history->retainedTurnNos);
        $this->assertSame(2, $history->positionTurnNo);
        $this->assertSame([1 => 'Hello', 2 => 'Write a test.'], $history->promptsByTurnNo);
        $this->assertSame(1, $history->predecessorTurnNo(2));
        $this->assertSame(0, $history->predecessorTurnNo(1));
    }

    #[Test]
    public function testInternalTurnsRetainedButAbsentFromPrompts(): void
    {
        $events = [
            $this->event(1, 0, RunEventTypeEnum::RunStarted->value, [
                'payload' => ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Do tools']]]]],
            ]),
            $this->event(2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            // Internal tool-cycle turn: no human command between anchors.
            $this->event(3, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2, 'step_id' => 'advance-after-tools']),
            $this->event(4, 2, RunEventTypeEnum::LlmStepCompleted->value),
            $this->event(5, 2, RunEventTypeEnum::AgentCommandApplied->value, [
                'kind' => 'follow_up',
                'text' => 'Next human',
            ]),
            $this->event(6, 3, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 3]),
        ];

        $history = $this->projector->build($events);
        $this->assertSame([1, 2, 3], $history->retainedTurnNos);
        $this->assertSame([1 => 'Do tools', 3 => 'Next human'], $history->promptsByTurnNo);
        $this->assertArrayNotHasKey(2, $history->promptsByTurnNo);
        // Predecessor of human prompt 3 is hidden internal turn 2.
        $this->assertSame(2, $history->predecessorTurnNo(3));
    }

    #[Test]
    public function testAppendMessageAndAssistantTextDoNotCreatePrompts(): void
    {
        $events = [
            $this->event(1, 0, RunEventTypeEnum::RunStarted->value, [
                'payload' => ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Start']]]]],
            ]),
            $this->event(2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            $this->event(3, 1, RunEventTypeEnum::AgentCommandApplied->value, [
                'kind' => 'append_message',
                'text' => 'Generated context budget reminder',
            ]),
            $this->event(4, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2]),
            $this->event(5, 2, RunEventTypeEnum::LlmStepCompleted->value),
            $this->event(6, 1, RunEventTypeEnum::AgentCommandApplied->value, [
                'kind' => 'steer',
                'text' => 'Real steer',
            ]),
            $this->event(7, 3, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 3]),
        ];

        $history = $this->projector->build($events);
        $this->assertSame([1, 2, 3], $history->retainedTurnNos);
        $this->assertSame([1 => 'Start', 3 => 'Real steer'], $history->promptsByTurnNo);
        $this->assertArrayNotHasKey(2, $history->promptsByTurnNo);
    }

    #[Test]
    public function testExplicitPositionZeroPreserved(): void
    {
        $events = [
            $this->event(1, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            $this->event(2, 1, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 1]),
            $this->event(3, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2]),
            $this->event(4, 0, RunEventTypeEnum::HistoryPositionSet->value, [
                'position_turn_no' => 0,
                'reason' => 'history_select',
            ]),
        ];

        $history = $this->projector->build($events);
        $this->assertSame([1, 2], $history->retainedTurnNos);
        $this->assertSame(0, $history->positionTurnNo);
        $this->assertSame([], $history->retainedTurnNosThrough(0));
    }

    #[Test]
    public function testTailDiscardPrunesPromptMapAndNewTail(): void
    {
        $events = [
            $this->event(1, 0, RunEventTypeEnum::RunStarted->value, [
                'payload' => ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'P1']]]]],
            ]),
            $this->event(2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            $this->event(3, 1, RunEventTypeEnum::AgentCommandApplied->value, [
                'kind' => 'follow_up',
                'text' => 'P2',
            ]),
            $this->event(4, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2]),
            $this->event(5, 2, RunEventTypeEnum::AgentCommandApplied->value, [
                'kind' => 'follow_up',
                'text' => 'P3',
            ]),
            $this->event(6, 3, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 3]),
            $this->event(7, 1, RunEventTypeEnum::HistoryTailDiscarded->value, ['after_turn_no' => 1]),
            $this->event(8, 1, RunEventTypeEnum::AgentCommandApplied->value, [
                'kind' => 'follow_up',
                'text' => 'P4',
            ]),
            $this->event(9, 4, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 4]),
        ];

        $history = $this->projector->build($events);
        $this->assertSame([1, 4], $history->retainedTurnNos);
        $this->assertSame(4, $history->positionTurnNo);
        $this->assertSame([1 => 'P1', 4 => 'P4'], $history->promptsByTurnNo);
        $this->assertArrayNotHasKey(2, $history->promptsByTurnNo);
        $this->assertArrayNotHasKey(3, $history->promptsByTurnNo);
    }

    #[Test]
    public function testLatestAppliedHumanPromptWinsBeforeAnchor(): void
    {
        $events = [
            $this->event(1, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            $this->event(2, 1, RunEventTypeEnum::AgentCommandApplied->value, [
                'kind' => 'follow_up',
                'text' => 'first draft',
            ]),
            $this->event(3, 1, RunEventTypeEnum::AgentCommandApplied->value, [
                'kind' => 'steer',
                'text' => 'final draft',
            ]),
            $this->event(4, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2]),
        ];

        $history = $this->projector->build($events);
        $this->assertSame([2 => 'final draft'], $history->promptsByTurnNo);
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
