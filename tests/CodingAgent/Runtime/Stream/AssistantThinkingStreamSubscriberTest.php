<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Stream;

use Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Stream\AssistantThinkingStreamSubscriber;
use Ineersa\CodingAgent\Runtime\Stream\LlmStreamDispatchObserver;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingStart;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class AssistantThinkingStreamSubscriberTest extends TestCase
{
    public function testImplicitStartsRetryAndTerminalCleanupStayInvocationLocal(): void
    {
        $sink = new InMemoryRuntimeEventSink();
        $subscriber = new AssistantThinkingStreamSubscriber($sink);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($subscriber);
        $observer = new LlmStreamDispatchObserver($dispatcher);
        $observer->onStreamStart('run', 'a');
        $observer->onStreamStart('run', 'b');
        $observer->onDelta('run', 'a', new ThinkingDelta('A'));
        $observer->onDelta('run', 'b', new ThinkingDelta('B'));
        $events = iterator_to_array($sink->drain('run'));
        $this->assertCount(4, $events);
        $this->assertSame(RuntimeEventTypeEnum::AssistantThinkingStarted->value, $events[0]->type);
        $this->assertSame('run_a_thinking', $events[0]->payload['block_id']);
        $this->assertSame(RuntimeEventTypeEnum::AssistantThinkingStarted->value, $events[2]->type);
        $this->assertSame('run_b_thinking', $events[2]->payload['block_id']);
        $observer->onStreamStart('run', 'a');
        $observer->onDelta('run', 'b', new ThinkingDelta('B2'));
        $events = iterator_to_array($sink->drain('run'));
        $this->assertCount(1, $events);
        $this->assertSame(RuntimeEventTypeEnum::AssistantThinkingDelta->value, $events[0]->type);
        $observer->onStreamEnd('run', 'a');
        $observer->onDelta('run', 'b', new ThinkingDelta('B3'));
        $events = iterator_to_array($sink->drain('run'));
        $this->assertCount(1, $events);
        $this->assertSame('B3', $events[0]->payload['thinking']);
        $observer->onStreamError('run', 'b', new \RuntimeException('cancelled'));
        $this->assertSame([], (new \ReflectionProperty($subscriber, 'started'))->getValue($subscriber));
    }

    public function testExplicitStartAndCompletionKeepSequentialPayloadSemantics(): void
    {
        $sink = new InMemoryRuntimeEventSink();
        $subscriber = new AssistantThinkingStreamSubscriber($sink);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($subscriber);
        $observer = new LlmStreamDispatchObserver($dispatcher);
        $observer->onStreamStart('run', null);
        $observer->onDelta('run', null, new ThinkingStart());
        $observer->onDelta('run', null, new ThinkingDelta('reason'));
        $observer->onDelta('run', null, new ThinkingComplete('reason', 'signature'));
        $observer->onStreamEnd('run', null);
        $events = iterator_to_array($sink->drain('run'));
        $this->assertCount(3, $events);
        $this->assertSame(RuntimeEventTypeEnum::AssistantThinkingStarted->value, $events[0]->type);
        $this->assertSame(RuntimeEventTypeEnum::AssistantThinkingCompleted->value, $events[2]->type);
        $this->assertSame(['thinking' => 'reason', 'signature' => 'signature', 'block_id' => 'run_thinking'], $events[2]->payload);
        $this->assertSame([], (new \ReflectionProperty($subscriber, 'started'))->getValue($subscriber));
    }
}
