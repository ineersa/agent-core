<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Process;

use Ineersa\CodingAgent\Runtime\Process\RuntimeEventPerRunCompactBuffer;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\AssistantStreamProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

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

    public function testTextDeltasCoalesceToConcatenatedProductionTextKey(): void
    {
        $buffer = new RuntimeEventPerRunCompactBuffer();
        $runId = 'child-run';
        $blockId = $runId.'_step-1_text';
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::AssistantTextStarted->value, $runId, 0, [
            'block_id' => $blockId,
            'step_id' => 'step-1',
            'text' => 'A',
        ]));
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::AssistantTextDelta->value, $runId, 0, [
            'block_id' => $blockId,
            'step_id' => 'step-1',
            'text' => 'B',
        ]));
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::AssistantTextDelta->value, $runId, 0, [
            'block_id' => $blockId,
            'step_id' => 'step-1',
            'text' => 'C',
        ]));

        $drained = iterator_to_array($buffer->drain($runId));
        $this->assertCount(2, $drained);
        $this->assertSame('A', $drained[0]->payload['text'] ?? null);
        $this->assertSame('BC', $drained[1]->payload['text'] ?? null);
        $this->assertSame('ABC', $this->projectAssistantTextFromDrained($drained));
    }

    public function testThinkingAndToolArgumentDeltasUseProductionPayloadKeys(): void
    {
        $buffer = new RuntimeEventPerRunCompactBuffer();
        $runId = 'child-run';
        $thinkingBlock = $runId.'_step-1_thinking';
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::AssistantThinkingDelta->value, $runId, 0, [
            'block_id' => $thinkingBlock,
            'step_id' => 'step-1',
            'thinking' => 'why',
        ]));
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::AssistantThinkingDelta->value, $runId, 0, [
            'block_id' => $thinkingBlock,
            'step_id' => 'step-1',
            'thinking' => ' now',
        ]));

        $toolCallId = 'call_abc';
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::ToolCallArgumentsDelta->value, $runId, 0, [
            'tool_call_id' => $toolCallId,
            'tool_name' => 'bash',
            'partial_json' => '{"cmd":',
        ]));
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::ToolCallArgumentsDelta->value, $runId, 0, [
            'tool_call_id' => $toolCallId,
            'tool_name' => 'bash',
            'partial_json' => '"ls"}',
        ]));

        $drained = iterator_to_array($buffer->drain($runId));
        $byType = [];
        foreach ($drained as $event) {
            $byType[$event->type][] = $event;
        }

        $this->assertSame('why now', $byType[RuntimeEventTypeEnum::AssistantThinkingDelta->value][0]->payload['thinking'] ?? null);
        $this->assertSame('{"cmd":"ls"}', $byType[RuntimeEventTypeEnum::ToolCallArgumentsDelta->value][0]->payload['partial_json'] ?? null);
    }

    public function testDurableMessageCompletedPrunesTransientTailWithMismatchedBlockId(): void
    {
        $buffer = new RuntimeEventPerRunCompactBuffer();
        $runId = 'child-run';
        $messageId = 'step-42';
        $textBlock = $runId.'_'.$messageId.'_text';
        $thinkingBlock = $runId.'_'.$messageId.'_thinking';

        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::AssistantTextStarted->value, $runId, 0, [
            'block_id' => $textBlock,
            'step_id' => $messageId,
            'text' => 'Hello',
        ]));
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::AssistantTextDelta->value, $runId, 0, [
            'block_id' => $textBlock,
            'step_id' => $messageId,
            'text' => ' world',
        ]));
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::AssistantThinkingDelta->value, $runId, 0, [
            'block_id' => $thinkingBlock,
            'step_id' => $messageId,
            'thinking' => 'trace',
        ]));
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::AssistantMessageCompleted->value, $runId, 99, [
            'message_id' => $messageId,
            'text' => 'Hello world',
            'details' => ['thinking' => 'trace'],
        ]));

        $this->assertSame([], iterator_to_array($buffer->drain($runId)));
    }

    public function testSeqZeroThinkingCompletedPrunesMatchingThinkingDeltas(): void
    {
        $buffer = new RuntimeEventPerRunCompactBuffer();
        $runId = 'child-run';
        $blockId = $runId.'_step-1_thinking';
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::AssistantThinkingDelta->value, $runId, 0, [
            'block_id' => $blockId,
            'step_id' => 'step-1',
            'thinking' => 'partial',
        ]));
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::AssistantThinkingCompleted->value, $runId, 0, [
            'block_id' => $blockId,
            'step_id' => 'step-1',
            'thinking' => 'partial',
        ]));

        $this->assertSame([], iterator_to_array($buffer->drain($runId)));
    }

    public function testProtectedHumanInputSurvivesRunCompleted(): void
    {
        $buffer = new RuntimeEventPerRunCompactBuffer();
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::HumanInputRequested->value, 'child', 0, [
            'question_id' => 'q1',
        ]));
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::AssistantTextDelta->value, 'child', 0, [
            'block_id' => 'child_step_text',
            'text' => 'tail',
        ]));
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::RunCompleted->value, 'child', 99, []));

        $drained = iterator_to_array($buffer->drain('child'));
        $this->assertCount(2, $drained);
        $this->assertSame(RuntimeEventTypeEnum::HumanInputRequested->value, $drained[0]->type);
        $this->assertSame(RuntimeEventTypeEnum::RunCompleted->value, $drained[1]->type);
        $types = array_map(static fn (RuntimeEvent $e): string => $e->type, $drained);
        $this->assertNotContains(RuntimeEventTypeEnum::AssistantTextDelta->value, $types);
    }

    public function testCoalescesRepeatedDeltasToSingleTailEntry(): void
    {
        $buffer = new RuntimeEventPerRunCompactBuffer();
        $blockId = 'child_step_text';
        for ($i = 0; $i < 5000; ++$i) {
            $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::AssistantTextDelta->value, 'child', 0, [
                'block_id' => $blockId,
                'text' => 'x',
            ]));
        }

        $this->assertSame(1, $buffer->totalTailCount());
        $drained = iterator_to_array($buffer->drain('child'));
        $this->assertCount(1, $drained);
        $this->assertSame(str_repeat('x', 5000), $drained[0]->payload['text'] ?? null);
    }

    /**
     * @param list<RuntimeEvent> $events
     */
    private function projectAssistantTextFromDrained(array $events): string
    {
        $dispatcher = new EventDispatcher();
        $state = new TranscriptProjectionState();
        $dispatcher->addSubscriber(new AssistantStreamProjectionSubscriber());
        $projector = new TranscriptProjector($dispatcher, $state);

        foreach ($events as $event) {
            $projector->accept([
                'type' => $event->type,
                'runId' => $event->runId,
                'seq' => $event->seq,
                'payload' => $event->payload,
            ]);
        }

        $text = '';
        foreach ($projector->blocks() as $block) {
            if (TranscriptBlockKindEnum::AssistantMessage === $block->kind) {
                $text .= $block->text;
            }
        }

        return $text;
    }
}
