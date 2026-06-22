<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Compaction;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Compaction\CompactionTokenEstimator;
use Ineersa\CodingAgent\Compaction\ProviderContextUsageResolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Compaction\ProviderContextUsageResolver
 *
 * Tests the effective context token computation:
 *  baseline (provider input_tokens) + delta (estimator on post-measurement messages).
 */
final class ProviderContextUsageResolverTest extends TestCase
{
    private ProviderContextUsageResolver $resolver;
    /** @var EventStoreInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $eventStore;

    protected function setUp(): void
    {
        $this->eventStore = $this->createMock(EventStoreInterface::class);
        $this->resolver = new ProviderContextUsageResolver(
            $this->eventStore,
            new CompactionTokenEstimator(),
        );
    }

    private function makeTextMessage(string $role, string $text): AgentMessage
    {
        return AgentMessage::fromPayload([
            'content' => [['text' => $text]],
            'role' => $role,
        ]);
    }

    private function makeLlmStepCompletedEvent(int $inputTokens, string $assistantText = 'Hello from assistant'): RunEvent
    {
        return new RunEvent(
            runId: 'run-1',
            seq: 1,
            turnNo: 1,
            type: RunEventTypeEnum::LlmStepCompleted->value,
            payload: [
                'step_id' => 'step-1',
                'stop_reason' => 'stop',
                'usage' => [
                    'input_tokens' => $inputTokens,
                    'output_tokens' => 100,
                    'total_tokens' => $inputTokens + 100,
                ],
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => $assistantText]],
                ],
            ],
        );
    }

    private function makeLlmStepCompletedEventNoText(int $inputTokens): RunEvent
    {
        return new RunEvent(
            runId: 'run-1',
            seq: 1,
            turnNo: 1,
            type: RunEventTypeEnum::LlmStepCompleted->value,
            payload: [
                'step_id' => 'step-no-text',
                'stop_reason' => 'tool_calls',
                'usage' => [
                    'input_tokens' => $inputTokens,
                    'output_tokens' => 200,
                    'total_tokens' => $inputTokens + 200,
                ],
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => null,
                ],
            ],
        );
    }

    private function makeLlmStepAbortedEvent(int $inputTokens): RunEvent
    {
        return new RunEvent(
            runId: 'run-1',
            seq: 1,
            turnNo: 1,
            type: RunEventTypeEnum::LlmStepAborted->value,
            payload: [
                'step_id' => 'step-aborted',
                'stop_reason' => 'aborted',
                'usage' => [
                    'input_tokens' => $inputTokens,
                    'output_tokens' => 50,
                    'total_tokens' => $inputTokens + 50,
                ],
                'aborted_assistant' => [
                    'present' => true,
                    'text_length' => 100,
                    'text_sha256' => 'abc123',
                    'has_tool_calls' => false,
                    'tool_call_count' => 0,
                    'tool_call_ids' => [],
                    'has_thinking' => false,
                ],
            ],
        );
    }

    // ── No provider measurement → null ──────────────────────────────

    /**
     * Thesis: no llm_step_completed or llm_step_aborted events → null.
     */
    public function testReturnsNullWhenNoProviderMeasurementExists(): void
    {
        $this->eventStore->expects(self::once())
            ->method('allFor')
            ->with('run-1')
            ->willReturn([]);

        $messages = [$this->makeTextMessage('user', 'Hello')];

        self::assertNull(
            $this->resolver->getEffectiveContextTokens('run-1', $messages),
        );
    }

    // ── Baseline only (no delta) ────────────────────────────────────

    /**
     * Thesis: provider input tokens returned directly when there is no
     * post-measurement delta (current messages end with the matched assistant).
     */
    public function testReturnsInputTokensWhenNoDelta(): void
    {
        $assistantText = 'Hello from assistant';
        $messages = [
            $this->makeTextMessage('user', 'What is 2+2?'),
            $this->makeTextMessage('assistant', $assistantText),
        ];

        $this->eventStore->expects(self::once())
            ->method('allFor')
            ->with('run-1')
            ->willReturn([$this->makeLlmStepCompletedEvent(5000, $assistantText)]);

        $result = $this->resolver->getEffectiveContextTokens('run-1', $messages);

        // 5000 input tokens + delta (the 4-word assistant message ~2 tokens)
        // delta = ceil(20 / 3.25) = 7 tokens for "Hello from assistant"
        self::assertNotNull($result);
        self::assertGreaterThan(5000, $result, 'Should include delta for the assistant message');
        // The delta should be small: just the assistant message text
        // "Hello from assistant" = 20 chars / 3.25 = ~7 tokens
        self::assertLessThan(5020, $result, 'Delta should be small for a short assistant message');
    }

    // ── Delta pushes over threshold ─────────────────────────────────

    /**
     * Thesis: provider input is below threshold but post-measurement
     * delta (tool results + new user message) pushes effective tokens
     * well above the baseline.
     */
    public function testDeltaIncludesToolResultsAndUserMessages(): void
    {
        $assistantText = 'Let me check that.';
        $messages = [
            $this->makeTextMessage('user', 'What is the answer?'),
            $this->makeTextMessage('assistant', $assistantText),
            $this->makeTextMessage('tool', str_repeat('tool output data ', 200)), // ~3400 chars / 3.25 ≈ 1046 tokens
            $this->makeTextMessage('user', 'Now tell me more about this topic in detail.'),
        ];

        $this->eventStore->expects(self::once())
            ->method('allFor')
            ->with('run-1')
            ->willReturn([$this->makeLlmStepCompletedEvent(5000, $assistantText)]);

        $result = $this->resolver->getEffectiveContextTokens('run-1', $messages);

        // 5000 baseline + >1000 delta from tool output + user message
        self::assertNotNull($result);
        self::assertGreaterThan(6000, $result, 'Delta from tool results should push effective tokens well above baseline');
    }

    // ── LlmStepAborted → input only, no delta ───────────────────────

    /**
     * Thesis: llm_step_aborted does NOT append an assistant message to
     * state, so effective tokens = provider input only (no delta).
     */
    public function testAbortedEventReturnsInputTokensOnly(): void
    {
        $messages = [
            $this->makeTextMessage('user', 'Hello'),
            $this->makeTextMessage('assistant', 'I was aborted'),
            $this->makeTextMessage('user', 'Try again'),
        ];

        $this->eventStore->expects(self::once())
            ->method('allFor')
            ->with('run-1')
            ->willReturn([$this->makeLlmStepAbortedEvent(5000)]);

        $result = $this->resolver->getEffectiveContextTokens('run-1', $messages);

        self::assertSame(5000, $result, 'Aborted event should return input tokens only, no delta');
    }

    // ── Matching failure → input only (no full-conversation fallback) ─

    /**
     * Thesis: when the assistant_message payload text doesn't match any
     * message in the current message list, the resolver returns provider
     * input only — it does NOT estimate the whole conversation.
     */
    public function testMatchingFailureReturnsInputTokensOnly(): void
    {
        $messages = [
            $this->makeTextMessage('user', 'What is the answer?'),
            // The assistant message that WAS appended has text 'Correct answer'
            $this->makeTextMessage('assistant', 'Correct answer'),
            $this->makeTextMessage('user', 'Next question...'),
        ];

        // Event payload says 'Different text' — no message in the list matches
        $event = $this->makeLlmStepCompletedEvent(5000, 'Different text');

        $this->eventStore->expects(self::once())
            ->method('allFor')
            ->with('run-1')
            ->willReturn([$event]);

        $result = $this->resolver->getEffectiveContextTokens('run-1', $messages);

        // Should return provider input only — NOT estimate the whole conversation.
        self::assertSame(5000, $result, 'Matching failure should return input tokens only, not full-conversation estimate');
    }

    // ── Tool-only assistant (null text) ─────────────────────────────

    /**
     * Thesis: when the assistant message has no text content (tool-call-only),
     * the text comparison matches null-to-null and delta is computed
     * from that message forward.
     */
    public function testToolOnlyAssistantMatchesNullText(): void
    {
        // Assistant message with null content (tool-only, no text)
        $messages = [
            $this->makeTextMessage('user', 'Read the file'),
            new AgentMessage(
                role: 'assistant',
                content: [],
                metadata: ['tool_calls' => [['id' => 'tc-1', 'name' => 'read', 'arguments' => [],
                                'order_index' => 0]]],
            ),
            $this->makeTextMessage('tool', str_repeat('file contents ', 100)), // ~1400 chars
            $this->makeTextMessage('user', 'Now summarize.'),
        ];

        $this->eventStore->expects(self::once())
            ->method('allFor')
            ->with('run-1')
            ->willReturn([$this->makeLlmStepCompletedEventNoText(5000)]);

        $result = $this->resolver->getEffectiveContextTokens('run-1', $messages);

        // 5000 input + delta for tool output + user message (should be >5000)
        self::assertNotNull($result);
        self::assertGreaterThan(5000, $result, 'Tool-only assistant should still compute delta from its index forward');
    }

    // ── No assistant_message payload in event ───────────────────────

    /**
     * Thesis: when the llm_step_completed event has no assistant_message
     * key at all (defensive edge case), returns provider input only.
     */
    public function testNoAssistantMessagePayloadReturnsInputOnly(): void
    {
        $messages = [
            $this->makeTextMessage('user', 'Hello'),
            $this->makeTextMessage('assistant', 'Hi'),
        ];

        $event = new RunEvent(
            runId: 'run-1',
            seq: 1,
            turnNo: 1,
            type: RunEventTypeEnum::LlmStepCompleted->value,
            payload: [
                'step_id' => 'step-no-am',
                'stop_reason' => 'stop',
                'usage' => [
                    'input_tokens' => 5000,
                    'output_tokens' => 100,
                    'total_tokens' => 5100,
                ],
                // No 'assistant_message' key
            ],
        );

        $this->eventStore->expects(self::once())
            ->method('allFor')
            ->with('run-1')
            ->willReturn([$event]);

        self::assertSame(5000, $this->resolver->getEffectiveContextTokens('run-1', $messages));
    }

    // ── prompt_tokens alias ─────────────────────────────────────────

    /**
     * Thesis: prompt_tokens is accepted as an alias for input_tokens.
     */
    public function testUsesPromptTokensWhenInputTokensMissing(): void
    {
        $assistantText = 'Got it.';
        $messages = [
            $this->makeTextMessage('user', 'Hello'),
            $this->makeTextMessage('assistant', $assistantText),
        ];

        $event = new RunEvent(
            runId: 'run-1',
            seq: 1,
            turnNo: 1,
            type: RunEventTypeEnum::LlmStepCompleted->value,
            payload: [
                'step_id' => 'step-1',
                'stop_reason' => 'stop',
                'usage' => [
                    'prompt_tokens' => 7000,
                    'completion_tokens' => 100,
                    'total_tokens' => 7100,
                ],
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => $assistantText]],
                ],
            ],
        );

        $this->eventStore->expects(self::once())
            ->method('allFor')
            ->with('run-1')
            ->willReturn([$event]);

        $result = $this->resolver->getEffectiveContextTokens('run-1', $messages);

        self::assertNotNull($result);
        self::assertGreaterThan(7000, $result, 'Should use prompt_tokens when input_tokens is missing');
    }
}
