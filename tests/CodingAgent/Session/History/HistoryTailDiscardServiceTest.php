<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\History;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Session\History\HistoryProjector;
use Ineersa\CodingAgent\Session\History\HistoryTailDiscardService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(HistoryTailDiscardService::class)]
final class HistoryTailDiscardServiceTest extends TestCase
{
    /**
     * Thesis: mutate-behind-tip must append history_tail_discarded so forward turns
     * leave active history; without it, abandoned future stays selectable/replayable.
     */
    public function testDiscardForwardTailWhenBehindTip(): void
    {
        $runId = 'discard-test';
        $events = [
            $this->event($runId, 1, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            $this->event($runId, 2, 1, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 1]),
            $this->event($runId, 3, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2]),
            $this->event($runId, 4, 2, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 2]),
            $this->event($runId, 5, 1, RunEventTypeEnum::HistoryPositionSet->value, [
                'position_turn_no' => 1,
                'reason' => 'history_select',
            ]),
        ];

        $store = $this->createMock(PreparedTransitionEventStoreInterface::class);
        $store->method('allFor')->willReturn($events);
        $store->expects($this->never())->method('appendTransition');

        $service = new HistoryTailDiscardService(
            $store,
            new HistoryProjector(),
            new NullLogger(),
        );
        $state = new RunState(
            runId: $runId,
            status: RunStatus::Completed,
            version: 1,
            turnNo: 1,
            lastSeq: 5,
        );

        $result = $service->prepareForwardTailDiscard($runId, $state);

        $appended = $result;
        $this->assertNotNull($appended);
        $this->assertSame(0, $appended->seq);
        $this->assertInstanceOf(RunEvent::class, $appended);
        $this->assertSame(RunEventTypeEnum::HistoryTailDiscarded->value, $appended->type);
        $this->assertSame(1, $appended->payload['after_turn_no']);
    }

    public function testNoDiscardWhenAtTip(): void
    {
        $runId = 'discard-tip';
        $events = [
            $this->event($runId, 1, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            $this->event($runId, 2, 1, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 1]),
        ];

        $store = $this->createMock(PreparedTransitionEventStoreInterface::class);
        $store->method('allFor')->willReturn($events);
        $store->expects($this->never())->method('appendTransition');

        $service = new HistoryTailDiscardService(
            $store,
            new HistoryProjector(),
            new NullLogger(),
        );
        $state = new RunState(
            runId: $runId,
            status: RunStatus::Completed,
            version: 1,
            turnNo: 1,
            lastSeq: 2,
        );

        $result = $service->prepareForwardTailDiscard($runId, $state);
        $this->assertNull($result);
    }

    public function testDetectsMutatingMessages(): void
    {
        $service = new HistoryTailDiscardService(
            $this->createStub(EventStoreInterface::class),
            new HistoryProjector(),
            new NullLogger(),
        );

        $this->assertTrue($service->isContextMutatingMessage(new AdvanceRun(
            runId: 'r',
            turnNo: 1,
            stepId: 's',
            attempt: 1,
            idempotencyKey: 'k',
        )));
        $this->assertTrue($service->isContextMutatingMessage(new ApplyCommand(
            runId: 'r',
            turnNo: 1,
            stepId: 's',
            attempt: 1,
            idempotencyKey: 'k',
            kind: 'follow_up',
            payload: ['text' => 'x'],
        )));
        $this->assertFalse($service->isContextMutatingMessage(new ApplyCommand(
            runId: 'r',
            turnNo: 1,
            stepId: 's',
            attempt: 1,
            idempotencyKey: 'k',
            kind: 'select_history_turn',
            payload: [],
        )));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(string $runId, int $seq, int $turnNo, string $type, array $payload): RunEvent
    {
        return new RunEvent(
            runId: $runId,
            seq: $seq,
            turnNo: $turnNo,
            type: $type,
            payload: $payload,
        );
    }
}
