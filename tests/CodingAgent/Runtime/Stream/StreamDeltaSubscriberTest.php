<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Stream;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Stream\AssistantTextStreamSubscriber;
use Ineersa\CodingAgent\Runtime\Stream\AssistantThinkingStreamSubscriber;
use Ineersa\CodingAgent\Runtime\Stream\LlmStreamDispatchObserver;
use Ineersa\CodingAgent\Runtime\Stream\ToolCallStreamSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Tests the subscriber-based stream delta architecture:
 * LlmStreamDispatchObserver → Symfony EventDispatcher → family subscribers.
 */
#[CoversClass(AssistantTextStreamSubscriber::class)]
#[CoversClass(AssistantThinkingStreamSubscriber::class)]
#[CoversClass(ToolCallStreamSubscriber::class)]
#[CoversClass(LlmStreamDispatchObserver::class)]
final class StreamDeltaSubscriberTest extends TestCase
{
    /** @var list<RuntimeEvent> */
    private array $captured = [];

    private LlmStreamDispatchObserver $observer;

    protected function setUp(): void
    {
        $this->captured = [];

        $sink = new class($this->captured) implements RuntimeEventSinkInterface {
            /** @param list<RuntimeEvent> $captured */
            public function __construct(private array &$captured)
            {
            }

            public function emit(RuntimeEvent $event): void
            {
                $this->captured[] = $event;
            }
        };

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new AssistantTextStreamSubscriber($sink));
        $dispatcher->addSubscriber(new AssistantThinkingStreamSubscriber($sink));
        $dispatcher->addSubscriber(new ToolCallStreamSubscriber($sink));

        $this->observer = new LlmStreamDispatchObserver($dispatcher);
    }

    // ── Stream lifecycle ─────────────────────────────────────────────────────

    #[Test]
    public function onStreamStartEmitsMessageStarted(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');

        $this->assertCount(1, $this->captured);
        $this->assertSame(RuntimeEventTypeEnum::AssistantMessageStarted->value, $this->captured[0]->type);
        $this->assertSame('run-1', $this->captured[0]->runId);
        $this->assertSame('step-1', $this->captured[0]->payload['step_id']);
        $this->assertSame(0, $this->captured[0]->seq);
    }

    // ── Text delta mapping ───────────────────────────────────────────────────

    #[Test]
    public function firstTextDeltaEmitsTextStarted(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->captured = [];

        $this->observer->onDelta('run-1', 'step-1', new TextDelta('Hello'));

        $this->assertCount(1, $this->captured);
        $this->assertSame(RuntimeEventTypeEnum::AssistantTextStarted->value, $this->captured[0]->type);
        $this->assertSame('Hello', $this->captured[0]->payload['text']);
        $this->assertStringContainsString('text', $this->captured[0]->payload['block_id'] ?? '');
    }

    #[Test]
    public function subsequentTextDeltaEmitsTextDelta(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->observer->onDelta('run-1', 'step-1', new TextDelta('Hello'));
        $this->observer->onDelta('run-1', 'step-1', new TextDelta(' World'));

        $events = array_values(array_filter(
            $this->captured,
            static fn (RuntimeEvent $e): bool => RuntimeEventTypeEnum::AssistantTextDelta->value === $e->type,
        ));

        $this->assertCount(1, $events);
        $this->assertSame(' World', $events[0]->payload['text']);
    }

    // ── Thinking delta mapping ───────────────────────────────────────────────

    #[Test]
    public function thinkingStartEmitsThinkingStarted(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->captured = [];

        $this->observer->onDelta('run-1', 'step-1', new ThinkingStart());

        $this->assertCount(1, $this->captured);
        $this->assertSame(RuntimeEventTypeEnum::AssistantThinkingStarted->value, $this->captured[0]->type);
        $this->assertStringContainsString('thinking', $this->captured[0]->payload['block_id'] ?? '');
    }

    #[Test]
    public function thinkingDeltaEmitsThinkingDelta(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->observer->onDelta('run-1', 'step-1', new ThinkingStart());
        $this->observer->onDelta('run-1', 'step-1', new ThinkingDelta('ponder...'));

        $events = array_values(array_filter(
            $this->captured,
            static fn (RuntimeEvent $e): bool => RuntimeEventTypeEnum::AssistantThinkingDelta->value === $e->type,
        ));

        $this->assertCount(1, $events);
        $this->assertSame('ponder...', $events[0]->payload['thinking']);
    }

    #[Test]
    public function thinkingDeltaWithoutStartEmitsImplicitStart(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->captured = [];

        $this->observer->onDelta('run-1', 'step-1', new ThinkingDelta('lonely thought'));

        $types = array_map(static fn (RuntimeEvent $e): string => $e->type, $this->captured);

        $this->assertContains(RuntimeEventTypeEnum::AssistantThinkingStarted->value, $types);
        $this->assertContains(RuntimeEventTypeEnum::AssistantThinkingDelta->value, $types);
    }

    #[Test]
    public function thinkingCompleteEmitsThinkingCompleted(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->captured = [];

        $this->observer->onDelta('run-1', 'step-1', new ThinkingComplete('full thought', 'sig-42'));

        $this->assertCount(1, $this->captured);
        $this->assertSame(RuntimeEventTypeEnum::AssistantThinkingCompleted->value, $this->captured[0]->type);
        $this->assertSame('full thought', $this->captured[0]->payload['thinking']);
        $this->assertSame('sig-42', $this->captured[0]->payload['signature']);
    }

    // ── Tool call delta mapping ──────────────────────────────────────────────

    #[Test]
    public function toolCallStartEmitsToolCallStarted(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->captured = [];

        $this->observer->onDelta('run-1', 'step-1', new ToolCallStart('tc-1', 'search_file'));

        $this->assertCount(1, $this->captured);
        $this->assertSame(RuntimeEventTypeEnum::ToolCallStarted->value, $this->captured[0]->type);
        $this->assertSame('tc-1', $this->captured[0]->payload['tool_call_id']);
        $this->assertSame('search_file', $this->captured[0]->payload['tool_name']);
        $this->assertSame('tool_call_tc-1', $this->captured[0]->payload['block_id']);
    }

    #[Test]
    public function toolInputDeltaEmitsToolCallArgumentsDelta(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->captured = [];

        $this->observer->onDelta('run-1', 'step-1', new ToolInputDelta('tc-1', 'search_file', '{"pattern"'));

        $this->assertCount(1, $this->captured);
        $this->assertSame(RuntimeEventTypeEnum::ToolCallArgumentsDelta->value, $this->captured[0]->type);
        $this->assertSame('{"pattern"', $this->captured[0]->payload['partial_json']);
    }

    #[Test]
    public function toolCallCompleteEmitsToolCallArgumentsCompleted(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->captured = [];

        $toolCall = new ToolCall('tc-1', 'search_file', ['pattern' => '*.php']);
        $this->observer->onDelta('run-1', 'step-1', new ToolCallComplete([$toolCall]));

        $this->assertCount(1, $this->captured);
        $this->assertSame(RuntimeEventTypeEnum::ToolCallArgumentsCompleted->value, $this->captured[0]->type);
        $this->assertSame('tc-1', $this->captured[0]->payload['tool_call_id']);
        $this->assertSame(['pattern' => '*.php'], $this->captured[0]->payload['arguments']);
    }

    // ── Empty-ID suppression (defense-in-depth) ──────────────────────────────

    #[Test]
    public function toolCallStartWithEmptyIdIsSuppressed(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->captured = [];

        $this->observer->onDelta('run-1', 'step-1', new ToolCallStart('', 'phantom'));

        $this->assertCount(0, $this->captured, 'Empty-ID ToolCallStart must be suppressed');
    }

    #[Test]
    public function toolInputDeltaWithEmptyIdIsSuppressed(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->captured = [];

        $this->observer->onDelta('run-1', 'step-1', new ToolInputDelta('', 'phantom', '{"x":1}'));

        $this->assertCount(0, $this->captured, 'Empty-ID ToolInputDelta must be suppressed');
    }

    #[Test]
    public function toolCallStartWithValidIdStillEmits(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->captured = [];

        $this->observer->onDelta('run-1', 'step-1', new ToolCallStart('tc-42', 'bash'));

        $this->assertCount(1, $this->captured, 'Non-empty ToolCallStart should still emit');
        $this->assertSame(RuntimeEventTypeEnum::ToolCallStarted->value, $this->captured[0]->type);
        $this->assertSame('tc-42', $this->captured[0]->payload['tool_call_id']);
    }

    // ── Seq and payload invariants ───────────────────────────────────────────

    #[Test]
    public function allTransientEventsHaveSeqZero(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->observer->onDelta('run-1', 'step-1', new TextDelta('Hello'));
        $this->observer->onDelta('run-1', 'step-1', new ThinkingStart());
        $this->observer->onDelta('run-1', 'step-1', new ToolCallStart('tc-1', 'bash'));

        foreach ($this->captured as $event) {
            $this->assertSame(0, $event->seq, \sprintf('Event type %s should have seq=0', $event->type));
        }
    }

    #[Test]
    public function stepIdPropagatesToPayload(): void
    {
        $this->observer->onStreamStart('run-1', 's-42');
        $this->captured = [];

        $this->observer->onDelta('run-1', 's-42', new TextDelta('x'));

        $this->assertSame('s-42', $this->captured[0]->payload['step_id']);
    }

    #[Test]
    public function nullStepIdDoesNotAppearInPayload(): void
    {
        $this->observer->onStreamStart('run-1', null);
        $this->captured = [];

        $this->observer->onDelta('run-1', null, new TextDelta('x'));

        $this->assertArrayNotHasKey('step_id', $this->captured[0]->payload);
    }

    // ── State reset on new stream ────────────────────────────────────────────

    #[Test]
    public function stateResetsBetweenStreams(): void
    {
        // First stream
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->observer->onDelta('run-1', 'step-1', new TextDelta('First'));
        $this->observer->onDelta('run-1', 'step-1', new TextDelta(' stream'));
        $this->captured = [];

        // Second stream — textStarted should reset
        $this->observer->onStreamStart('run-2', 'step-2');
        $this->observer->onDelta('run-2', 'step-2', new TextDelta('Second'));

        $this->assertCount(2, $this->captured); // message_started + text_started
        $this->assertSame(RuntimeEventTypeEnum::AssistantTextStarted->value, $this->captured[1]->type);
        $this->assertSame('Second', $this->captured[1]->payload['text']);
    }

    // ── Text extraction edge case ────────────────────────────────────────────

    #[Test]
    public function textEmptyDeltaProducesTextStarted(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->captured = [];

        $this->observer->onDelta('run-1', 'step-1', new TextDelta(''));

        $this->assertCount(1, $this->captured);
        $this->assertSame(RuntimeEventTypeEnum::AssistantTextStarted->value, $this->captured[0]->type);
        $this->assertSame('', $this->captured[0]->payload['text']);
    }
}
