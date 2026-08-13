<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Message;

use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Notification\ModelNotificationDTO;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: first typed delivery=tool_result_replace with non-empty text replaces
 * model-facing tool content; empty text falls back to normal tool content.
 */
final class AgentMessageNormalizerModelNotificationTest extends TestCase
{
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
            ],
            isError: false,
            error: null,
        );

        $notifications = [
            new ModelNotificationDTO(
                id: 'first',
                source: 'output_cap',
                kind: 'output_capped',
                severity: 'warning',
                delivery: 'tool_result_replace',
                text: 'replacement wins',
            ),
            new ModelNotificationDTO(
                id: 'second',
                source: 'output_cap',
                kind: 'output_capped',
                severity: 'warning',
                delivery: 'tool_result_replace',
                text: 'later ignored',
            ),
        ];

        $message = (new AgentMessageNormalizer())->toolMessage($result, $notifications);
        $this->assertSame('replacement wins', $message->content[0]['text'] ?? null);
    }

    public function testEmptyReplacementTextFallsBackToNormalContent(): void
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
            ],
            isError: false,
            error: null,
        );

        $notifications = [
            new ModelNotificationDTO(
                id: 'first',
                source: 'output_cap',
                kind: 'output_capped',
                severity: 'warning',
                delivery: 'tool_result_replace',
                text: '',
            ),
            new ModelNotificationDTO(
                id: 'second',
                source: 'output_cap',
                kind: 'output_capped',
                severity: 'warning',
                delivery: 'tool_result_replace',
                text: 'later must not win after empty first',
            ),
        ];

        $message = (new AgentMessageNormalizer())->toolMessage($result, $notifications);
        $this->assertSame('normal tool content', $message->content[0]['text'] ?? null);
    }

    public function testNoNotificationsUsesNormalContent(): void
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
            ],
            isError: false,
            error: null,
        );

        $message = (new AgentMessageNormalizer())->toolMessage($result);
        $this->assertSame('normal tool content', $message->content[0]['text'] ?? null);
    }
}
