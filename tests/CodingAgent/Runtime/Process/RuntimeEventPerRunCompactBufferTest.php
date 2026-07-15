<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Process;

use Ineersa\CodingAgent\Runtime\Process\RuntimeEventPerRunCompactBuffer;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Process\RuntimeEventPerRunCompactBuffer
 */
final class RuntimeEventPerRunCompactBufferTest extends TestCase
{
    public function testDurableLifecycleEventsRemainUntilDrainWhileStreamCheckpointsDropRawDeltas(): void
    {
        $buffer = new RuntimeEventPerRunCompactBuffer();
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::TurnStarted->value, 'child', 5, []));
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::RunCompleted->value, 'child', 6, []));

        $drained = iterator_to_array($buffer->drain('child'));
        $this->assertCount(2, $drained);
        $this->assertSame(RuntimeEventTypeEnum::TurnStarted->value, $drained[0]->type);
        $this->assertSame(RuntimeEventTypeEnum::RunCompleted->value, $drained[1]->type);
    }

    public function testTextCompletedPrunesMatchingDeltas(): void
    {
        $buffer = new RuntimeEventPerRunCompactBuffer();
        $blockId = 'block-1';
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::AssistantTextDelta->value, 'child', 0, [
            'block_id' => $blockId,
            'delta' => 'hello',
        ]));
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::AssistantTextCompleted->value, 'child', 12, [
            'block_id' => $blockId,
            'text' => 'hello',
        ]));

        $this->assertSame([], iterator_to_array($buffer->drain('child')));
    }

    public function testCoalescesRepeatedDeltasToSingleTailEntry(): void
    {
        $buffer = new RuntimeEventPerRunCompactBuffer();
        $blockId = 'block-2';
        for ($i = 0; $i < 5000; ++$i) {
            $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::AssistantTextDelta->value, 'child', 0, [
                'block_id' => $blockId,
                'delta' => 'x'.$i,
            ]));
        }

        $this->assertSame(1, $buffer->totalTailCount());
        $drained = iterator_to_array($buffer->drain('child'));
        $this->assertCount(1, $drained);
        $this->assertSame('x4999', $drained[0]->payload['delta'] ?? null);
    }

    public function testProtectedHumanInputSurvivesRunCompleted(): void
    {
        $buffer = new RuntimeEventPerRunCompactBuffer();
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::HumanInputRequested->value, 'child', 0, [
            'question_id' => 'q1',
        ]));
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::AssistantTextDelta->value, 'child', 0, [
            'block_id' => 'b',
            'delta' => 'tail',
        ]));
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::RunCompleted->value, 'child', 99, []));

        $drained = iterator_to_array($buffer->drain('child'));
        $this->assertCount(2, $drained);
        $this->assertSame(RuntimeEventTypeEnum::HumanInputRequested->value, $drained[0]->type);
        $this->assertSame(RuntimeEventTypeEnum::RunCompleted->value, $drained[1]->type);
        $types = array_map(static fn (RuntimeEvent $e): string => $e->type, $drained);
        $this->assertNotContains(RuntimeEventTypeEnum::AssistantTextDelta->value, $types);
    }
}
