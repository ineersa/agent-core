<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Stream;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink;
use Ineersa\CodingAgent\Runtime\Process\RuntimeEventPerRunCompactBuffer;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Stream\AssistantTextStreamSubscriber;
use Ineersa\CodingAgent\Runtime\Stream\LlmStreamDispatchObserver;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\MessageItem;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Result\Stream\Delta\MessageComplete;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Result\Stream\Delta\MessageStart;
use Symfony\AI\Platform\Result\Stream\Delta\CommentaryDelta;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class AssistantTextStreamSubscriberTest extends TestCase
{
    /** @var list<RuntimeEvent> */
    private array $events = [];
    private LlmStreamDispatchObserver $observer;

    protected function setUp(): void
    {
        $sink = new class($this->events) implements RuntimeEventSinkInterface {
            /** @param list<RuntimeEvent> $events */
            public function __construct(private array &$events)
            {
            }

            public function emit(RuntimeEvent $event): void
            {
                $this->events[] = $event;
            }
        };
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new AssistantTextStreamSubscriber($sink));
        $this->observer = new LlmStreamDispatchObserver($dispatcher);
        $this->observer->onStreamStart('run', 'step');
        $this->events = [];
    }

    public function testAdjacentEqualTextMessagesHaveIndependentPhaseBoundaries(): void
    {
        foreach (['commentary', 'commentary', 'final_answer'] as $index => $phase) {
            $item = self::item('message-'.$index, $phase, 'same');
            $this->observer->onDelta('run', 'step', new MessageStart($item, $index));
            $this->observer->onDelta('run', 'step', 'commentary' === $phase ? new CommentaryDelta('sa') : new TextDelta('sa'));
            $this->observer->onDelta('run', 'step', 'commentary' === $phase ? new CommentaryDelta('me') : new TextDelta('me'));
            $this->observer->onDelta('run', 'step', new MessageComplete(MessageItem::toText($item), $index));
        }
        $started = $this->ofType(RuntimeEventTypeEnum::AssistantTextStarted);
        $completed = $this->ofType(RuntimeEventTypeEnum::AssistantTextCompleted);
        $this->assertCount(3, $started);
        $this->assertCount(3, array_unique(array_column(array_column($started, 'payload'), 'block_id')));
        $this->assertSame(['commentary', 'commentary', 'final_answer'], array_column(array_column($completed, 'payload'), 'phase'));
        $this->assertSame(['same', 'same', 'same'], array_column(array_column($completed, 'payload'), 'text'));
        $this->assertSame([], $this->ofType(RuntimeEventTypeEnum::AssistantThinkingStarted));
        $buffer = new RuntimeEventPerRunCompactBuffer();
        foreach ($this->events as $event) {
            $buffer->ingest($event, observedRun: true);
        }
        $this->assertSame($completed, iterator_to_array($buffer->drain('run')), 'Each native message remains a distinct complete checkpoint after JSONL tail compaction.');
    }

    public function testTerminalOnlyAndEmptyMessagesDoNotDuplicateOrCreatePlaceholders(): void
    {
        $item = self::item('terminal', 'commentary', 'Checking');
        $this->observer->onDelta('run', 'step', new MessageComplete(MessageItem::toText($item), 0));
        $empty = self::item('empty', 'final_answer', '');
        $this->observer->onDelta('run', 'step', new MessageStart($empty, 1));
        $this->observer->onDelta('run', 'step', new TextDelta(''));
        $this->observer->onDelta('run', 'step', new MessageComplete(MessageItem::toText($empty), 1));
        $this->assertCount(2, $this->events);
        $this->assertSame('Checking', $this->events[0]->payload['text']);
        $this->assertSame($this->events[0]->payload['block_id'], $this->events[1]->payload['block_id']);
    }

    public function testAnnouncedPhaseAndRetryStateAreIndependent(): void
    {
        $item = self::item('announced', 'commentary', 'Checking');
        $this->observer->onDelta('run', 'step', new MessageStart($item, 0));
        $this->observer->onDelta('run', 'step', new TextDelta('Check'));
        unset($item['phase']);
        $this->observer->onDelta('run', 'step', new MessageComplete(MessageItem::toText($item), 0));
        $this->assertSame('commentary', $this->events[1]->payload['phase']);
        $this->assertSame('Checking', $this->events[1]->payload['text']);

        $this->observer->onStreamStart('run', 'step');
        $this->events = [];
        $this->observer->onDelta('run', 'step', new TextDelta('Unphased retry'));
        $this->assertCount(1, $this->events);
        $this->assertSame(RuntimeEventTypeEnum::AssistantTextStarted->value, $this->events[0]->type);
        $this->assertSame('run_step_text', $this->events[0]->payload['block_id']);
        $this->assertArrayNotHasKey('phase', $this->events[0]->payload);
    }

    public function testOverlappingNativeMessagesAndRetryKeepSiblingMetadata(): void
    {
        $a = self::item('item-a', 'commentary', 'A');
        $b = self::item('item-b', 'final_answer', 'B');
        $this->observer->onDelta('run', 'step', new MessageStart($a, 3));
        $this->observer->onStreamStart('run', 'sibling');
        $this->observer->onDelta('run', 'sibling', new MessageStart($b, 8));
        $this->observer->onDelta('run', 'step', new TextDelta('A'));
        $this->observer->onDelta('run', 'sibling', new TextDelta('B'));
        $started = $this->ofType(RuntimeEventTypeEnum::AssistantTextStarted);
        $this->assertSame('item-a', $started[0]->payload['item_id']);
        $this->assertSame(3, $started[0]->payload['output_index']);
        $this->assertSame('commentary', $started[0]->payload['phase']);
        $this->assertSame('run_step_text_item-a', $started[0]->payload['block_id']);
        $this->assertNotSame($started[0]->payload['block_id'], $started[1]->payload['block_id']);
        $this->observer->onStreamStart('run', 'step');
        $this->observer->onDelta('run', 'sibling', new TextDelta('B2'));
        $delta = $this->ofType(RuntimeEventTypeEnum::AssistantTextDelta)[0];
        $this->assertSame($started[1]->payload['block_id'], $delta->payload['block_id']);
        $this->assertSame('item-b', $delta->payload['item_id']);
        $this->assertSame(8, $delta->payload['output_index']);
        $this->assertSame('final_answer', $delta->payload['phase']);
        $this->observer->onStreamError('run', 'step', new \RuntimeException('cancelled'));
        $this->observer->onDelta('run', 'sibling', new MessageComplete(MessageItem::toText($b), 8));
        $complete = $this->ofType(RuntimeEventTypeEnum::AssistantTextCompleted)[0];
        $this->assertSame($delta->payload['block_id'], $complete->payload['block_id']);
        $this->assertSame('B', $complete->payload['text']);
        $this->observer->onStreamEnd('run', 'sibling');
    }

    public function testTerminalLifecycleReleasesOnlyItsOwnNativeState(): void
    {
        $sink = new InMemoryRuntimeEventSink();
        $subscriber = new AssistantTextStreamSubscriber($sink);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($subscriber);
        $observer = new LlmStreamDispatchObserver($dispatcher);
        $a = self::item('item-a', 'commentary', 'A');
        $b = self::item('item-b', 'final_answer', 'B');
        $observer->onStreamStart('a', null);
        $observer->onStreamStart('b', null);
        $observer->onDelta('a', null, new MessageStart($a, 1));
        $observer->onDelta('b', null, new MessageStart($b, 2));
        $observer->onStreamEnd('a', null);
        $observer->onDelta('b', null, new TextDelta('B'));
        $events = iterator_to_array($sink->drain('b'));
        $this->assertSame('item-b', $events[1]->payload['item_id']);
        $observer->onStreamError('b', null, new \RuntimeException('failure'));
        $this->assertSame([], (new \ReflectionProperty($subscriber, 'streams'))->getValue($subscriber));
    }

    public function testGenericTextStartsIndependentlyAndRetryDoesNotRestartSibling(): void
    {
        $this->observer->onStreamStart('other', 'step');
        $this->observer->onDelta('run', 'step', new TextDelta('A'));
        $this->observer->onDelta('other', 'step', new TextDelta('B'));
        $started = $this->ofType(RuntimeEventTypeEnum::AssistantTextStarted);
        $this->assertCount(2, $started);
        $this->assertSame('run_step_text', $started[0]->payload['block_id']);
        $this->assertSame('other_step_text', $started[1]->payload['block_id']);
        $this->observer->onStreamStart('run', 'step');
        $this->observer->onDelta('other', 'step', new TextDelta('B2'));
        $delta = $this->ofType(RuntimeEventTypeEnum::AssistantTextDelta);
        $this->assertCount(1, $delta);
        $this->assertSame('B2', $delta[0]->payload['text']);
        $this->observer->onStreamEnd('run', 'step');
        $this->observer->onStreamEnd('other', 'step');
    }

    /** @return list<RuntimeEvent> */
    private function ofType(RuntimeEventTypeEnum $type): array
    {
        return array_values(array_filter($this->events, static fn (RuntimeEvent $event): bool => $event->type === $type->value));
    }

    /** @return array<string, mixed> */
    private static function item(string $id, string $phase, string $text): array
    {
        return ['type' => 'message', 'id' => $id, 'role' => 'assistant', 'phase' => $phase, 'content' => [['type' => 'output_text', 'text' => $text]]];
    }
}
