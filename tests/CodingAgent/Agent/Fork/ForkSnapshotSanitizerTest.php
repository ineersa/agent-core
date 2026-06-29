<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Agent\Fork\ForkSnapshotSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ForkSnapshotSanitizer.
 *
 * Test thesis:
 *   - Trims the launch user message + fork tool call/result when a fork
 *     tool call exists in the last assistant message.
 *   - Leaves messages unchanged when no fork tool call is present.
 *   - Does NOT mutate the input array.
 *   - Handles edge cases: fork call without preceding user message,
 *     empty input, single message.
 */
#[CoversClass(ForkSnapshotSanitizer::class)]
final class ForkSnapshotSanitizerTest extends TestCase
{
    private ForkSnapshotSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new ForkSnapshotSanitizer();
    }

    // ── Helper to build test messages ───────────────────────────────────

    /**
     * Build an assistant message with tool_calls.
     *
     * @param array<int, array<string, mixed>> $toolCalls
     */
    private function assistantMessage(string $content, array $toolCalls = []): AgentMessage
    {
        $metadata = [];
        if ([] !== $toolCalls) {
            $metadata['tool_calls'] = $toolCalls;
        }

        return new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => $content]],
            metadata: $metadata,
        );
    }

    private function userMessage(string $content): AgentMessage
    {
        return new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => $content]],
        );
    }

    private function toolMessage(string $toolCallId, string $content): AgentMessage
    {
        return new AgentMessage(
            role: 'tool',
            content: [['type' => 'text', 'text' => $content]],
            toolCallId: $toolCallId,
        );
    }

    // ── Tests ────────────────────────────────────────────────────────────

    public function testSanitizeRemovesLaunchMessages(): void
    {
        $messages = [
            $this->userMessage('Earlier conversation'),
            $this->assistantMessage('Earlier response'),
            $this->userMessage('Launch fork'),          // ← this user message + everything after should be removed
            $this->assistantMessage('Calling fork', [
                ['id' => 'call_fork_1', 'name' => 'fork', 'arguments' => ['task' => 'do something']],
            ]),
            $this->toolMessage('call_fork_1', '{"status": "launched"}'),
            $this->assistantMessage('Fork launched'),
        ];

        $result = $this->sanitizer->sanitize($messages);

        // Should keep only messages before the launch user message.
        self::assertCount(2, $result);
        self::assertSame('Earlier conversation', $result[0]->content[0]['text']);
        self::assertSame('Earlier response', $result[1]->content[0]['text']);
    }

    public function testSanitizeNoForkCallReturnsUnchanged(): void
    {
        $messages = [
            $this->userMessage('Hello'),
            $this->assistantMessage('Hi there'),
            $this->userMessage('Continue'),
        ];

        $result = $this->sanitizer->sanitize($messages);

        self::assertCount(3, $result);
        self::assertSame($messages[0]->content[0]['text'], $result[0]->content[0]['text']);
        self::assertSame($messages[1]->content[0]['text'], $result[1]->content[0]['text']);
        self::assertSame($messages[2]->content[0]['text'], $result[2]->content[0]['text']);
    }

    public function testSanitizeDoesNotMutateInput(): void
    {
        $messages = [
            $this->userMessage('Launch fork'),
            $this->assistantMessage('Calling fork', [
                ['id' => 'call_fork_1', 'name' => 'fork', 'arguments' => []],
            ]),
        ];

        $originalCount = \count($messages);
        $originalContent = $messages[0]->content[0]['text'];

        $this->sanitizer->sanitize($messages);

        // Input unchanged.
        self::assertCount($originalCount, $messages);
        self::assertSame($originalContent, $messages[0]->content[0]['text']);
    }

    public function testSanitizeForkCallWithoutPrecedingUser(): void
    {
        // First message is already the fork call — no preceding user message.
        $messages = [
            $this->assistantMessage('Calling fork', [
                ['id' => 'call_fork_1', 'name' => 'fork', 'arguments' => []],
            ]),
            $this->toolMessage('call_fork_1', 'done'),
        ];

        $result = $this->sanitizer->sanitize($messages);

        // Should return empty since the fork call is at index 0.
        self::assertCount(0, $result);
    }

    public function testSanitizeEmptyInput(): void
    {
        $result = $this->sanitizer->sanitize([]);

        self::assertCount(0, $result);
    }

    public function testSanitizeIgnoresOtherToolCalls(): void
    {
        $messages = [
            $this->userMessage('User query'),
            $this->assistantMessage('Using read tool', [
                ['id' => 'call_read_1', 'name' => 'read', 'arguments' => ['path' => 'file.txt']],
            ]),
            $this->toolMessage('call_read_1', 'file contents'),
            $this->userMessage('Continue'),
        ];

        $result = $this->sanitizer->sanitize($messages);

        // No fork call → unchanged.
        self::assertCount(4, $result);
    }

    public function testSanitizeOnlyLastForkCallIsChecked(): void
    {
        // Multiple assistant messages with tool calls; only the last one
        // with a fork tool call should trigger trimming.
        $messages = [
            $this->userMessage('Earlier'),
            $this->assistantMessage('response'),
            $this->userMessage('Launch fork'),
            $this->assistantMessage('Doing fork', [
                ['id' => 'call_fork_1', 'name' => 'fork', 'arguments' => []],
            ]),
        ];

        $result = $this->sanitizer->sanitize($messages);

        self::assertCount(2, $result);
        self::assertSame('Earlier', $result[0]->content[0]['text']);
    }

    public function testSanitizeWithMixedToolCallsIncludingFork(): void
    {
        $messages = [
            $this->userMessage('Earlier conversation 1'),
            $this->assistantMessage('Response 1'),
            $this->userMessage('Earlier conversation 2'),       // ← preceding user for the fork call
            $this->assistantMessage('Mixed tools', [
                ['id' => 'call_read_1', 'name' => 'read', 'arguments' => ['path' => 'x.txt']],
                ['id' => 'call_fork_1', 'name' => 'fork', 'arguments' => ['task' => 'do']],
            ]),
            $this->toolMessage('call_read_1', 'file contents'),
            $this->toolMessage('call_fork_1', 'launched'),
        ];

        $result = $this->sanitizer->sanitize($messages);

        // Should keep messages before the launch user message (index 2).
        self::assertCount(2, $result);
        self::assertSame('Earlier conversation 1', $result[0]->content[0]['text']);
        self::assertSame('Response 1', $result[1]->content[0]['text']);
    }
}
