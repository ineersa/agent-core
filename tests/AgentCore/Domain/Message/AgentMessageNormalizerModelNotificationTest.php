<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Message;

use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: first array row with raw delivery=tool_result_replace wins even when
 * its text is empty/non-string; later valid replacement rows must not override.
 * Normal tool content then wins.
 */
final class AgentMessageNormalizerModelNotificationTest extends TestCase
{
    public function testFirstEmptyReplacementDoesNotFallThroughToLaterValidText(): void
    {
        $result = new ToolCallResult(
            runId: 'run-1',
            turnNo: 1,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: 'ik',
            toolCallId: 'c1',
            orderIndex: 0,
            result: [
                'content' => [['type' => 'text', 'text' => 'normal tool content']],
                'details' => [
                    'model_notifications' => [
                        'skip-me',
                        [
                            'id' => 'first',
                            'delivery' => 'tool_result_replace',
                            'text' => '',
                        ],
                        [
                            'id' => 'second',
                            'delivery' => 'tool_result_replace',
                            'text' => 'later replacement must not win',
                        ],
                    ],
                ],
            ],
            isError: false,
            error: null,
        );

        $message = (new AgentMessageNormalizer())->toolMessage($result);
        $this->assertSame('tool', $message->role);
        $this->assertSame('normal tool content', $message->content[0]['text'] ?? null);
    }

    public function testFirstNonStringReplacementTextFallsBackToNormalContent(): void
    {
        $result = new ToolCallResult(
            runId: 'run-1',
            turnNo: 1,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: 'ik',
            toolCallId: 'c1',
            orderIndex: 0,
            result: [
                'content' => [['type' => 'text', 'text' => 'normal tool content']],
                'details' => [
                    'model_notifications' => [
                        [
                            'id' => 'first',
                            'delivery' => 'tool_result_replace',
                            'text' => 123,
                        ],
                        [
                            'id' => 'second',
                            'delivery' => 'tool_result_replace',
                            'text' => 'later replacement must not win',
                        ],
                    ],
                ],
            ],
            isError: false,
            error: null,
        );

        $message = (new AgentMessageNormalizer())->toolMessage($result);
        $this->assertSame('normal tool content', $message->content[0]['text'] ?? null);
    }

    public function testFirstValidReplacementTextWins(): void
    {
        $result = new ToolCallResult(
            runId: 'run-1',
            turnNo: 1,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: 'ik',
            toolCallId: 'c1',
            orderIndex: 0,
            result: [
                'content' => [['type' => 'text', 'text' => 'normal tool content']],
                'details' => [
                    'model_notifications' => [
                        [
                            'id' => 'first',
                            'delivery' => 'tool_result_replace',
                            'text' => 'replacement wins',
                        ],
                        [
                            'id' => 'second',
                            'delivery' => 'tool_result_replace',
                            'text' => 'later ignored',
                        ],
                    ],
                ],
            ],
            isError: false,
            error: null,
        );

        $message = (new AgentMessageNormalizer())->toolMessage($result);
        $this->assertSame('replacement wins', $message->content[0]['text'] ?? null);
    }
}
