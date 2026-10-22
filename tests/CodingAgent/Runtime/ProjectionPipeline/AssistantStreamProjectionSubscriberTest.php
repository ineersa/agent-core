<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\ProjectionPipeline;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\AssistantStreamProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\Tui\Runtime\TuiSessionState;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\MessageItem;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class AssistantStreamProjectionSubscriberTest extends TestCase
{
    private TranscriptProjector $projector;

    protected function setUp(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new AssistantStreamProjectionSubscriber());
        $this->projector = new TranscriptProjector($dispatcher, new TranscriptProjectionState());
    }

    public function testCanonicalReplayRetainsAdjacentMessagesAndToolOrderWithoutSignaturesOnTheWire(): void
    {
        $completion = $this->completion();
        $this->assertSame('', $completion->payload['text']);
        $this->assertStringNotContainsString('encrypted_content', json_encode($completion->payload, \JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('text_signature', json_encode($completion->payload, \JSON_THROW_ON_ERROR));
        $this->projector->accept($completion);
        $blocks = $this->projector->blocks();
        $this->assertSame(['Summary', 'same', 'read()', 'same', 'Answer'], array_column($blocks, 'text'));
        $this->assertSame([TranscriptBlockKindEnum::AssistantThinking, TranscriptBlockKindEnum::AssistantMessage, TranscriptBlockKindEnum::ToolCall, TranscriptBlockKindEnum::AssistantMessage, TranscriptBlockKindEnum::AssistantMessage], array_column($blocks, 'kind'));
        $this->assertSame(['commentary', 'commentary', 'final_answer'], array_column(array_column([$blocks[1], $blocks[3], $blocks[4]], 'meta'), 'phase'));
        $this->assertSame('call', $blocks[2]->meta['tool_call_id']);
        $this->assertSame('second', $blocks[3]->meta['item_id']);
        $this->assertCount(5, array_unique(array_column($blocks, 'id')));
        $this->projector->accept($completion);
        $this->assertCount(5, $this->projector->blocks());
    }

    public function testLateTerminalMessagesReconcileIncrementallyWithoutLosingLocalUiBlocks(): void
    {
        $session = new TuiSessionState('run');
        $this->projector->accept(new RuntimeEvent(RuntimeEventTypeEnum::AssistantThinkingStarted->value, 'run', 0, ['step_id' => 'step', 'block_id' => 'thinking', 'text' => 'Summary']));
        $this->projector->accept(new RuntimeEvent(RuntimeEventTypeEnum::AssistantMessageCompleted->value, 'run', 0, ['message_id' => 'step', 'tool_calls' => [['id' => 'call', 'name' => 'read', 'arguments' => []]]]));
        $this->projector->accept(new RuntimeEvent(RuntimeEventTypeEnum::AssistantTextCompleted->value, 'run', 0, ['step_id' => 'step', 'block_id' => 'run_step_text_final', 'item_id' => 'final', 'output_index' => 4, 'phase' => 'final_answer', 'text' => 'Answer']));
        $session->applyTranscriptChangeSet($this->projector->drainChanges());
        // Native checkpoints must stand alone when process buffering prunes start/delta events.
        $this->assertSame(['Summary', 'read()', 'Answer'], array_column($session->transcript, 'text'));
        $session->transcript[] = new \Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock('local', TranscriptBlockKindEnum::System, 'run', 100, 'Local notification');
        $this->projector->accept($this->completion());
        $changes = $this->projector->drainChanges();
        $this->assertFalse($changes->isFull());
        $this->assertNotEmpty($changes->removals);
        $session->applyTranscriptChangeSet($changes);
        $this->assertContains('Local notification', array_column($session->transcript, 'text'));
        $content = array_values(array_filter($session->transcript, static fn ($block): bool => TranscriptBlockKindEnum::System !== $block->kind));
        $this->assertSame(['Summary', 'same', 'read()', 'same', 'Answer'], array_column($content, 'text'));
        $this->assertSame(array_column($this->projector->blocks(), 'id'), array_column($content, 'id'));
    }

    public function testRetryDropsOldNativeMessagesButLeavesUnphasedTextAlone(): void
    {
        $this->projector->accept(new RuntimeEvent(RuntimeEventTypeEnum::AssistantTextStarted->value, 'run', 0, ['step_id' => 'step', 'block_id' => 'native', 'item_id' => 'old', 'output_index' => 0, 'phase' => 'commentary', 'text' => 'Old attempt']));
        $this->projector->accept(new RuntimeEvent(RuntimeEventTypeEnum::AssistantTextStarted->value, 'run', 0, ['step_id' => 'other', 'block_id' => 'plain', 'text' => 'Plain']));
        $this->projector->accept(new RuntimeEvent(RuntimeEventTypeEnum::AssistantMessageStarted->value, 'run', 0, ['step_id' => 'step']));
        $this->assertSame(['Plain'], array_column($this->projector->blocks(), 'text'));
    }

    private function completion(): RuntimeEvent
    {
        $content = [['type' => 'thinking', 'text' => 'Summary', 'thinking_signature' => '{"type":"reasoning","encrypted_content":"opaque"}']];
        foreach ([['first', 'commentary', 'same'], ['second', 'commentary', 'same'], ['final', 'final_answer', 'Answer'], ['empty', 'final_answer', '']] as $index => [$id, $phase, $text]) {
            $signed = MessageItem::toText(['type' => 'message', 'id' => $id, 'role' => 'assistant', 'phase' => $phase, 'content' => [['type' => 'output_text', 'text' => $text]]]);
            $content[] = ['type' => 'text', 'text' => $text, 'text_signature' => $signed->getSignature()];
            if (0 === $index) {
                $content[] = ['type' => 'tool_call', 'tool_call_index' => 0];
            }
        }
        $translator = new RuntimeEventTranslator(new EventDispatcher(), new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer()), AttributeSerializerValidatorTestFactory::serializer());
        $event = $translator->translate(new RunEvent('run', 10, 1, 'llm_step_completed', ['step_id' => 'step', 'assistant_message' => ['content' => $content, 'details' => ['thinking' => 'Summary'], 'tool_calls' => [['id' => 'call', 'name' => 'read', 'arguments' => []]]]]));
        $this->assertNotNull($event);

        return $event;
    }
}
