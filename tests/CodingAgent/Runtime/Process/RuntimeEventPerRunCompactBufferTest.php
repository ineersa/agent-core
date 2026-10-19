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
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::TurnStarted->value, 'child', 5, []), true);
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::RunCompleted->value, 'child', 6, []), true);

        $drained = iterator_to_array($buffer->drain('child'));
        $this->assertCount(2, $drained);
        $this->assertSame(RuntimeEventTypeEnum::TurnStarted->value, $drained[0]->type);
        $this->assertSame(RuntimeEventTypeEnum::RunCompleted->value, $drained[1]->type);
    }

    public function testUnobservedDurableLifecycleIsNotRetained(): void
    {
        $buffer = new RuntimeEventPerRunCompactBuffer();
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::TurnStarted->value, 'child', 5, []));
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::RunCompleted->value, 'child', 6, []));

        $this->assertSame(0, $buffer->totalTailCount());
        $this->assertSame([], iterator_to_array($buffer->drain('child')));
    }

    public function testUnobservedNativeTextCheckpointsReplaceDuplicateSnapshotsWithoutDurableBacklog(): void
    {
        $buffer = new RuntimeEventPerRunCompactBuffer();
        foreach (['first', 'second'] as $index => $itemId) {
            $payload = ['block_id' => 'child_step_'.$itemId, 'step_id' => 'step', 'item_id' => $itemId, 'output_index' => $index, 'phase' => 'commentary'];
            $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::AssistantTextStarted->value, 'child', 0, $payload + ['text' => 'prefix']));
            $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::AssistantTextDelta->value, 'child', 0, $payload + ['text' => 'suffix']));
            for ($revision = 0; $revision < 100; ++$revision) {
                $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::AssistantTextCompleted->value, 'child', 0, $payload + ['text' => 'snapshot '.$revision]));
            }
        }
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::TurnStarted->value, 'child', 4, []));
        $buffer->releaseObservationRetention('child');

        $this->assertSame(2, $buffer->totalTailCount());
        $tail = iterator_to_array($buffer->drain('child'));
        $this->assertSame(['first', 'second'], array_column(array_column($tail, 'payload'), 'item_id'));
        foreach ($tail as $event) {
            $this->assertSame(RuntimeEventTypeEnum::AssistantTextCompleted->value, $event->type);
            $this->assertSame(0, $event->seq);
            $this->assertSame('snapshot 99', $event->payload['text']);
        }
        $this->assertSame(0, $buffer->totalTailCount());
    }

    public function testNativeSnapshotsArePrunedOnlyByMatchingDurableMessageOrRunTermination(): void
    {
        foreach ([RuntimeEventTypeEnum::AssistantMessageCompleted, RuntimeEventTypeEnum::RunCompleted, RuntimeEventTypeEnum::RunFailed, RuntimeEventTypeEnum::RunCancelled] as $boundary) {
            $buffer = new RuntimeEventPerRunCompactBuffer();
            foreach (['first', 'second'] as $index => $itemId) {
                $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::AssistantTextCompleted->value, 'child', 0, [
                    'block_id' => 'child_step_'.$itemId, 'step_id' => 'step', 'output_index' => $index, 'text' => $itemId,
                ]));
            }
            $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::AssistantMessageCompleted->value, 'child', 4, ['message_id' => 'other-step']));
            $this->assertSame(2, $buffer->totalTailCount(), 'Another step cannot supersede the unfinished native message.');
            $buffer->ingest(new RuntimeEvent($boundary->value, 'child', 5, ['message_id' => 'step']));
            $this->assertSame([], iterator_to_array($buffer->drain('child')), $boundary->value);
        }
    }

    public function testUnobservedUnphasedTextCompletionStillOnlyPrunes(): void
    {
        $buffer = new RuntimeEventPerRunCompactBuffer();
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::AssistantTextStarted->value, 'child', 0, ['block_id' => 'child_step_text', 'text' => 'generic']));
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::AssistantTextCompleted->value, 'child', 0, ['block_id' => 'child_step_text', 'text' => 'generic']));

        $this->assertSame([], iterator_to_array($buffer->drain('child')));
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
        $this->assertCount(1, $drained);
        $this->assertSame(RuntimeEventTypeEnum::HumanInputRequested->value, $drained[0]->type);
        $types = array_map(static fn (RuntimeEvent $e): string => $e->type, $drained);
        $this->assertNotContains(RuntimeEventTypeEnum::AssistantTextDelta->value, $types);
        $this->assertNotContains(RuntimeEventTypeEnum::RunCompleted->value, $types);
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

    public function testClearDropsEveryRetainedTail(): void
    {
        $buffer = new RuntimeEventPerRunCompactBuffer();
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::TurnStarted->value, 'old-run', 1, []), true);
        $buffer->ingest(new RuntimeEvent(RuntimeEventTypeEnum::AssistantTextDelta->value, 'old-run', 0, [
            'block_id' => 'old-run_text',
            'text' => 'stale',
        ]));

        $this->assertGreaterThan(0, $buffer->totalTailCount());

        $buffer->clear();

        $this->assertSame(0, $buffer->totalTailCount());
        $this->assertSame([], iterator_to_array($buffer->drain('old-run')));
    }

    public function testFragmentDrainPreservesPayloadPrecedenceAndSplitBytesForEveryStreamType(): void
    {
        foreach ([false, true] as $observed) {
            foreach ([
                'assistant.text_started' => 'text',
                'assistant.text_delta' => 'text',
                'assistant.thinking_started' => 'thinking',
                'assistant.thinking_delta' => 'thinking',
                'tool_call.arguments_delta' => 'partial_json',
                'tool_execution.output_delta' => 'delta',
            ] as $type => $field) {
                $buffer = new RuntimeEventPerRunCompactBuffer();
                $chunks = [substr('🙂', 0, 2), null, substr('🙂', 2), "\0tail"];
                foreach ($chunks as $index => $chunk) {
                    $buffer->ingest(new RuntimeEvent($type, 'child', 0, [
                        'block_id' => 'block', 'tool_call_id' => 'call', $field => $chunk,
                        'latest' => $index,
                    ] + (0 === $index ? ['first_only' => true] : []), 7), $observed);
                }
                $tail = iterator_to_array($buffer->drain('child'));
                $this->assertCount(1, $tail, $type);
                $this->assertSame([
                    'v' => 1, 'type' => $type, 'runId' => 'child', 'seq' => 0,
                    'payload' => ['block_id' => 'block', 'tool_call_id' => 'call', $field => "🙂\0tail", 'latest' => 3, 'first_only' => true],
                ], $tail[0]->toArray(), $type);
                $this->assertSame([], iterator_to_array($buffer->drain('child')));
            }
        }
    }

    public function testPrunedSlotsDoNotMixFragmentsAfterObservationReleaseOrSessionClear(): void
    {
        $buffer = new RuntimeEventPerRunCompactBuffer();
        $buffer->ingest(new RuntimeEvent('turn.started', 'child', 1), true);
        foreach (['old', 'discard'] as $text) {
            $buffer->ingest(new RuntimeEvent('assistant.text_delta', 'child', 0, ['block_id' => 'old', 'text' => $text]));
        }
        $buffer->ingest(new RuntimeEvent('human_input.requested', 'child', 0, ['question_id' => 'q']));
        $buffer->ingest(new RuntimeEvent('assistant.text_completed', 'child', 2, ['block_id' => 'old']), true);
        $buffer->releaseObservationRetention('child');
        foreach (['new', '🙂'] as $text) {
            $buffer->ingest(new RuntimeEvent('assistant.text_delta', 'child', 0, ['block_id' => 'new', 'text' => $text]));
        }
        $tail = iterator_to_array($buffer->drain('child'));
        $this->assertSame(['human_input.requested', 'assistant.text_delta'], array_column($tail, 'type'));
        $this->assertSame('new🙂', $tail[1]->payload['text']);

        foreach (['stale', 'prefix'] as $text) {
            $buffer->ingest(new RuntimeEvent('assistant.text_delta', 'child', 0, ['text' => $text]));
        }
        $buffer->clear();
        foreach (['fresh', 'suffix'] as $text) {
            $buffer->ingest(new RuntimeEvent('assistant.text_delta', 'child', 0, ['text' => $text]));
        }
        $this->assertSame('freshsuffix', iterator_to_array($buffer->drain('child'))[0]->payload['text']);
    }

    public function testSubagentProgressReplacesFragmentsAndCannotBeOverwrittenByRawOutput(): void
    {
        $buffer = new RuntimeEventPerRunCompactBuffer();
        foreach (['discard', 'prefix'] as $delta) {
            $buffer->ingest(new RuntimeEvent('tool_execution.output_delta', 'child', 0, ['tool_call_id' => 'call', 'delta' => $delta]));
        }
        $latest = new RuntimeEvent('tool_execution.output_delta', 'child', 0, ['tool_call_id' => 'call', 'subagent_progress' => ['revision' => 2]], 9);
        $buffer->ingest(new RuntimeEvent('tool_execution.output_delta', 'child', 0, ['tool_call_id' => 'call', 'subagent_progress' => ['revision' => 1]]));
        $buffer->ingest($latest);
        $buffer->ingest(new RuntimeEvent('tool_execution.output_delta', 'child', 0, ['tool_call_id' => 'call', 'delta' => 'ignored']));
        $this->assertSame([$latest], iterator_to_array($buffer->drain('child')));
    }

    public function testFragmentsStayUnjoinedUntilDrainAndCheckpointsDiscardThem(): void
    {
        $buffer = new RuntimeEventPerRunCompactBuffer();
        $chunks = ['first', 'second', 'third'];
        foreach ($chunks as $text) {
            $buffer->ingest(new RuntimeEvent('assistant.text_delta', 'child', 0, ['block_id' => 'block', 'text' => $text]));
        }
        $fragments = new \ReflectionProperty($buffer, 'fragmentsByRunId');
        $this->assertSame(['child' => [$chunks]], $fragments->getValue($buffer));
        $buffer->ingest(new RuntimeEvent('assistant.text_completed', 'child', 4, ['block_id' => 'block']));
        $this->assertSame([], $fragments->getValue($buffer));
        $this->assertSame([], iterator_to_array($buffer->drain('child')));
    }

    public function testAbandonedDrainKeepsMaterializedEventAndRemainingFragments(): void
    {
        $buffer = new RuntimeEventPerRunCompactBuffer();
        foreach (['a', 'b'] as $block) {
            foreach (['first', 'second'] as $text) {
                $buffer->ingest(new RuntimeEvent('assistant.text_delta', 'child', 0, ['block_id' => $block, 'text' => $text]));
            }
        }
        $drain = $buffer->drain('child');
        $this->assertSame('firstsecond', $drain->current()->payload['text']);
        unset($drain);
        $tail = iterator_to_array($buffer->drain('child'));
        $this->assertSame(['a', 'b'], array_column(array_column($tail, 'payload'), 'block_id'));
        $this->assertSame(['firstsecond', 'firstsecond'], array_column(array_column($tail, 'payload'), 'text'));
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
            $projector->accept($event);
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
