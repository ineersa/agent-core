<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Stream;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Stream\RuntimeEventStreamObserver;
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

#[CoversClass(RuntimeEventStreamObserver::class)]
final class RuntimeEventStreamObserverTest extends TestCase
{
    /** @var list<RuntimeEvent> */
    private array $captured = [];

    private RuntimeEventStreamObserver $observer;

    protected function setUp(): void
    {
        $this->captured = [];

        $sink = new class($this->captured) implements RuntimeEventSinkInterface {
            /** @param list<RuntimeEvent> $captured */
            public function __construct(private array &$captured) {}

            public function emit(RuntimeEvent $event): void
            {
                $this->captured[] = $event;
            }
        };

        $this->observer = new RuntimeEventStreamObserver($sink);
    }

    #[Test]
    public function onStreamStartEmitsMessageStarted(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');

        self::assertCount(1, $this->captured);
        self::assertSame(RuntimeEventTypeEnum::AssistantMessageStarted->value, $this->captured[0]->type);
        self::assertSame('run-1', $this->captured[0]->runId);
        self::assertSame(0, $this->captured[0]->seq);
        self::assertSame('step-1', $this->captured[0]->payload['step_id']);
    }

    #[Test]
    public function firstTextDeltaEmitsTextStarted(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->captured = []; // Reset after stream-start

        $this->observer->onDelta('run-1', 'step-1', new TextDelta('Hello'));

        self::assertCount(1, $this->captured);
        self::assertSame(RuntimeEventTypeEnum::AssistantTextStarted->value, $this->captured[0]->type);
        self::assertSame('Hello', $this->captured[0]->payload['text']);
        self::assertStringContainsString('text', $this->captured[0]->payload['block_id'] ?? '');
    }

    #[Test]
    public function subsequentTextDeltaEmitsTextDelta(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->observer->onDelta('run-1', 'step-1', new TextDelta('Hello'));
        $this->observer->onDelta('run-1', 'step-1', new TextDelta(' World'));

        // Index 0 = message_started, 1 = text_started, 2 = text_delta
        self::assertCount(3, $this->captured);
        self::assertSame(RuntimeEventTypeEnum::AssistantTextDelta->value, $this->captured[2]->type);
        self::assertSame(' World', $this->captured[2]->payload['text']);
    }

    #[Test]
    public function thinkingStartEmitsThinkingStarted(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->captured = [];

        $this->observer->onDelta('run-1', 'step-1', new ThinkingStart());

        self::assertCount(1, $this->captured);
        self::assertSame(RuntimeEventTypeEnum::AssistantThinkingStarted->value, $this->captured[0]->type);
        self::assertStringContainsString('thinking', $this->captured[0]->payload['block_id'] ?? '');
    }

    #[Test]
    public function thinkingDeltaWithoutStartEmitsImplicitStart(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->captured = [];

        $this->observer->onDelta('run-1', 'step-1', new ThinkingDelta('Hmm...'));

        // Should emit thinking_started implicitly, then thinking_delta
        self::assertCount(2, $this->captured);
        self::assertSame(RuntimeEventTypeEnum::AssistantThinkingStarted->value, $this->captured[0]->type);
        self::assertSame(RuntimeEventTypeEnum::AssistantThinkingDelta->value, $this->captured[1]->type);
        self::assertSame('Hmm...', $this->captured[1]->payload['thinking']);
    }

    #[Test]
    public function thinkingDeltaAfterStartOnlyEmitsDelta(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->observer->onDelta('run-1', 'step-1', new ThinkingStart());
        $this->captured = [];

        $this->observer->onDelta('run-1', 'step-1', new ThinkingDelta('Hmm...'));

        self::assertCount(1, $this->captured);
        self::assertSame(RuntimeEventTypeEnum::AssistantThinkingDelta->value, $this->captured[0]->type);
    }

    #[Test]
    public function thinkingCompleteEmitsCompleted(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->observer->onDelta('run-1', 'step-1', new ThinkingStart());
        $this->captured = [];

        $this->observer->onDelta('run-1', 'step-1', new ThinkingComplete('Full thought', 'sig123'));

        self::assertCount(1, $this->captured);
        self::assertSame(RuntimeEventTypeEnum::AssistantThinkingCompleted->value, $this->captured[0]->type);
        self::assertSame('Full thought', $this->captured[0]->payload['thinking']);
        self::assertSame('sig123', $this->captured[0]->payload['signature']);
    }

    #[Test]
    public function toolCallStartEmitsStarted(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->captured = [];

        $this->observer->onDelta('run-1', 'step-1', new ToolCallStart('tc_1', 'search'));

        self::assertCount(1, $this->captured);
        self::assertSame(RuntimeEventTypeEnum::ToolCallStarted->value, $this->captured[0]->type);
        self::assertSame('tc_1', $this->captured[0]->payload['tool_call_id']);
        self::assertSame('search', $this->captured[0]->payload['tool_name']);
        self::assertSame('tool_call_tc_1', $this->captured[0]->payload['block_id']);
    }

    #[Test]
    public function toolInputDeltaEmitsArgumentsDelta(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->captured = [];

        $this->observer->onDelta('run-1', 'step-1', new ToolInputDelta('tc_1', 'search', '{"query":'));

        self::assertCount(1, $this->captured);
        self::assertSame(RuntimeEventTypeEnum::ToolCallArgumentsDelta->value, $this->captured[0]->type);
        self::assertSame('tc_1', $this->captured[0]->payload['tool_call_id']);
        self::assertSame('{"query":', $this->captured[0]->payload['partial_json']);
    }

    #[Test]
    public function toolCallCompleteEmitsArgumentsCompletedPerCall(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->captured = [];

        $toolCall = new ToolCall('tc_1', 'search', ['query' => 'test']);
        $this->observer->onDelta('run-1', 'step-1', new ToolCallComplete([$toolCall]));

        self::assertCount(1, $this->captured);
        self::assertSame(RuntimeEventTypeEnum::ToolCallArgumentsCompleted->value, $this->captured[0]->type);
        self::assertSame('tc_1', $this->captured[0]->payload['tool_call_id']);
        self::assertSame(['query' => 'test'], $this->captured[0]->payload['arguments']);
    }

    #[Test]
    public function toolCallCompleteWithMultipleCalls(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->captured = [];

        $toolCalls = [
            new ToolCall('tc_1', 'search', ['q' => 'a']),
            new ToolCall('tc_2', 'read', ['path' => 'f.txt']),
        ];
        $this->observer->onDelta('run-1', 'step-1', new ToolCallComplete($toolCalls));

        self::assertCount(2, $this->captured);
        self::assertSame(RuntimeEventTypeEnum::ToolCallArgumentsCompleted->value, $this->captured[0]->type);
        self::assertSame(RuntimeEventTypeEnum::ToolCallArgumentsCompleted->value, $this->captured[1]->type);
        self::assertSame('tc_1', $this->captured[0]->payload['tool_call_id']);
        self::assertSame('tc_2', $this->captured[1]->payload['tool_call_id']);
    }

    #[Test]
    public function allTransientEventsHaveSeqZero(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->observer->onDelta('run-1', 'step-1', new TextDelta('Hello'));
        $this->observer->onDelta('run-1', 'step-1', new TextDelta(' World'));
        $this->observer->onDelta('run-1', 'step-1', new ToolCallStart('tc_1', 'search'));
        $this->observer->onDelta('run-1', 'step-1', new ThinkingStart());

        foreach ($this->captured as $event) {
            self::assertSame(0, $event->seq, \sprintf('Event type %s should have seq=0', $event->type));
        }
    }

    #[Test]
    public function streamEndDoesNotEmitDuplicateMessageCompleted(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $beforeCount = \count($this->captured);

        $this->observer->onStreamEnd('run-1', 'step-1');

        // onStreamEnd should NOT emit any event (message_completed is handled by durable mapper)
        self::assertCount($beforeCount, $this->captured);
    }

    #[Test]
    public function streamErrorDoesNotEmitTransientEvent(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $beforeCount = \count($this->captured);

        $this->observer->onStreamError('run-1', 'step-1', new \RuntimeException('boom'));

        self::assertCount($beforeCount, $this->captured);
    }

    #[Test]
    public function stateResetsPerStreamStart(): void
    {
        // First stream: text delta emits text_started
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->observer->onDelta('run-1', 'step-1', new TextDelta('First'));
        self::assertSame(RuntimeEventTypeEnum::AssistantTextStarted->value, $this->captured[1]->type);

        // Second stream: fresh start, text delta should emit text_started again
        $this->observer->onStreamStart('run-2', 'step-2');
        $this->observer->onDelta('run-2', 'step-2', new TextDelta('Second'));

        // Find the second text_started
        $textStartedCount = 0;
        foreach ($this->captured as $event) {
            if (RuntimeEventTypeEnum::AssistantTextStarted->value === $event->type) {
                ++$textStartedCount;
            }
        }

        self::assertSame(2, $textStartedCount, 'Each stream should emit its own text_started');
    }

    #[Test]
    public function stepIdIncludedInPayload(): void
    {
        $this->observer->onStreamStart('run-1', 'step-99');
        $this->observer->onDelta('run-1', 'step-99', new TextDelta('x'));

        self::assertSame('step-99', $this->captured[1]->payload['step_id']);
    }

    #[Test]
    public function nullStepIdAccepted(): void
    {
        $this->observer->onStreamStart('run-1', null);
        $this->observer->onDelta('run-1', null, new TextDelta('x'));

        self::assertArrayNotHasKey('step_id', $this->captured[1]->payload);
    }

    #[Test]
    public function thinkingSignatureIsSilentlySkipped(): void
    {
        $this->observer->onStreamStart('run-1', 'step-1');
        $this->captured = [];

        $this->observer->onDelta('run-1', 'step-1',
            new \Symfony\AI\Platform\Result\Stream\Delta\ThinkingSignature('sig123'));

        self::assertCount(0, $this->captured,
            'ThinkingSignature should not emit a transient event (embedded in final block)');
    }
}
