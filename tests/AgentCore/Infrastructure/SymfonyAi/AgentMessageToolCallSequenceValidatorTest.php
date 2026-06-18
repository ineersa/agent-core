<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageToolCallSequenceValidator;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\MalformedToolCallSequenceException;
use PHPUnit\Framework\TestCase;

final class AgentMessageToolCallSequenceValidatorTest extends TestCase
{
    private AgentMessageToolCallSequenceValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new AgentMessageToolCallSequenceValidator();
    }

    public function testValidSequencePasses(): void
    {
        $messages = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Hello']]),
            $this->assistantWithToolCalls(['tc-1', 'tc-2']),
            $this->toolResult('tc-1', false),
            $this->toolResult('tc-2', false),
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Next']]),
        ];

        // Should not throw
        $this->validator->validate($messages);
        $this->expectNotToPerformAssertions();
    }

    public function testValidSequenceWithoutToolCallsPasses(): void
    {
        $messages = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Hello']]),
            new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Hi']]),
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Next']]),
        ];

        $this->validator->validate($messages);
        $this->expectNotToPerformAssertions();
    }

    public function testMissingToolResultThrows(): void
    {
        $messages = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Call tools']]),
            $this->assistantWithToolCalls(['tc-1']),
            // No tool result for tc-1
        ];

        $this->expectException(MalformedToolCallSequenceException::class);
        $this->expectExceptionMessage('missing');
        $this->expectExceptionMessage('tc-1');

        $this->validator->validate($messages);
    }

    public function testUserMessageBeforeToolResultsThrows(): void
    {
        $messages = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Call tools']]),
            $this->assistantWithToolCalls(['tc-1']),
            // User message before tool result for tc-1
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Wait']]),
        ];

        $this->expectException(MalformedToolCallSequenceException::class);
        $this->expectExceptionMessage('unclosed');
        $this->expectExceptionMessage('tc-1');

        $this->validator->validate($messages);
    }

    public function testSystemMessageBeforeToolResultsThrowsUnclosed(): void
    {
        $messages = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Call tools']]),
            $this->assistantWithToolCalls(['tc-1']),
            // System message before tool result for tc-1
            new AgentMessage(role: 'system', content: [['type' => 'text', 'text' => 'You are a helpful assistant.']]),
        ];

        $this->expectException(MalformedToolCallSequenceException::class);
        $this->expectExceptionMessage('unclosed');
        $this->expectExceptionMessage('tc-1');

        $this->validator->validate($messages);
    }

    public function testOrphanToolMessageThrows(): void
    {
        $messages = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Hello']]),
            new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Hi']]),
            $this->toolResult('orphan-tc', false),
        ];

        $this->expectException(MalformedToolCallSequenceException::class);
        $this->expectExceptionMessage('orphan');
        $this->expectExceptionMessage('orphan-tc');

        $this->validator->validate($messages);
    }

    public function testUnknownToolCallIdThrows(): void
    {
        $messages = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Call tools']]),
            $this->assistantWithToolCalls(['real-tc-1']),
            $this->toolResult('unknown-tc', false),
        ];

        $this->expectException(MalformedToolCallSequenceException::class);
        $this->expectExceptionMessage('unknown');
        $this->expectExceptionMessage('unknown-tc');

        $this->validator->validate($messages);
    }

    public function testDuplicateToolResultThrows(): void
    {
        $messages = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Call tools']]),
            $this->assistantWithToolCalls(['tc-1', 'tc-2']),
            $this->toolResult('tc-1', false),
            $this->toolResult('tc-2', false),
            // Second tool result for tc-1 — duplicate
            $this->toolResult('tc-1', false),
        ];

        $this->expectException(MalformedToolCallSequenceException::class);
        $this->expectExceptionMessage('duplicate');
        $this->expectExceptionMessage('duplicate_tool_result');
        $this->expectExceptionMessage('tc-1');

        $this->validator->validate($messages);
    }

    public function testEmptySequencePasses(): void
    {
        $this->validator->validate([]);
        $this->expectNotToPerformAssertions();
    }

    public function testAssistantWithoutToolCallsFollowedByToolMessageThrows(): void
    {
        // Assistant without tool calls, then a tool message with no open batch
        $messages = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Hello']]),
            new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'No tools here']]),
            $this->toolResult('orphan-tc', false),
        ];

        $this->expectException(MalformedToolCallSequenceException::class);
        $this->expectExceptionMessage('orphan');

        $this->validator->validate($messages);
    }

    /**
     * Create an assistant message with tool_calls in metadata.
     *
     * @param list<string> $toolCallIds
     */
    private function assistantWithToolCalls(array $toolCallIds): AgentMessage
    {
        $toolCalls = [];
        foreach ($toolCallIds as $index => $id) {
            $toolCalls[] = [
                'id' => $id,
                'name' => \sprintf('tool_%s', $id),
                'arguments' => [],
                'order_index' => $index,
            ];
        }

        return new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => 'Using tools']],
            metadata: ['tool_calls' => $toolCalls],
        );
    }

    private function toolResult(string $toolCallId, bool $isError = false): AgentMessage
    {
        return new AgentMessage(
            role: 'tool',
            content: [['type' => 'text', 'text' => 'Result']],
            toolCallId: $toolCallId,
            isError: $isError,
        );
    }
}
